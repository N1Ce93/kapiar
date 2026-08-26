<?php

namespace App\Services\Monitoring;

use App\Models\MonitoredSite;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ArticleDiscoveryService
{
    public function __construct(private readonly SiteProbeService $probeService) {}

    /** @return list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> */
    public function discover(MonitoredSite $site, int $limit = 50): array
    {
        if ($site->source_type === 'rss') {
            return $this->discoverFromRssSite($site, $limit);
        }

        return $this->discoverFromHtmlSite($site, $limit);
    }

    /** @return list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> */
    private function discoverFromRssSite(MonitoredSite $site, int $limit): array
    {
        if ($site->feed_url) {
            return $this->discoverFromFeed($site->feed_url, $limit);
        }

        return $this->discoverFromWordPressApi($site, $limit);
    }

    /** @return list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> */
    private function discoverFromWordPressApi(MonitoredSite $site, int $limit): array
    {
        $apiBase = UrlHelper::withoutTrailingSlash($site->base_url).'/wp-json/wp/v2/posts';
        $items = [];
        $perPage = min(100, max(1, $limit));

        for ($page = 1; count($items) < $limit && $page <= 20; $page++) {
            try {
                $response = Http::withHeaders(UrlHelper::crawlerHeaders())
                    ->withoutVerifying()
                    ->timeout(20)
                    ->retry(1, 500)
                    ->get($apiBase, [
                        'per_page' => $perPage,
                        'page' => $page,
                        '_fields' => 'link,title,date,excerpt',
                    ]);
            } catch (Throwable) {
                return [];
            }

            if (! $response->successful() || ! is_array($response->json()) || $response->json() === []) {
                break;
            }

            foreach ($response->json() as $post) {
                $url = isset($post['link']) ? UrlHelper::cleanUrl((string) $post['link']) : null;

                if (! $url) {
                    continue;
                }

                $items[] = [
                    'url' => $url,
                    'title' => $this->cleanText($post['title']['rendered'] ?? null),
                    'excerpt' => $this->cleanText($post['excerpt']['rendered'] ?? null),
                    'published_at' => $this->parseDate($post['date'] ?? null),
                ];
            }
        }

        return array_slice($this->uniqueByUrl($items), 0, $limit);
    }

    /** @return list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> */
    private function discoverFromFeed(?string $feedUrl, int $limit): array
    {
        if (! $feedUrl) {
            return [];
        }

        try {
            $response = Http::withHeaders(UrlHelper::crawlerHeaders())
                ->withoutVerifying()
                ->timeout(20)
                ->retry(1, 500)
                ->get($feedUrl);
        } catch (Throwable $exception) {
            throw new RuntimeException('RSS feed request failed: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('RSS feed returned HTTP '.$response->status().': '.$feedUrl);
        }

        $body = ltrim($response->body(), "\xEF\xBB\xBF\t\n\r\0\x0B ");

        try {
            $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        } catch (Throwable $exception) {
            throw new RuntimeException('RSS feed could not be parsed: '.$exception->getMessage(), previous: $exception);
        }

        if (! $xml) {
            throw new RuntimeException('RSS feed could not be parsed: '.$feedUrl);
        }

        $items = [];

        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $url = UrlHelper::cleanUrl((string) $item->link);

                if ($url === '') {
                    continue;
                }

                $namespaces = $item->getNameSpaces(true);
                $encodedContent = isset($namespaces['content'])
                    ? (string) $item->children($namespaces['content'])->encoded
                    : '';

                $items[] = [
                    'url' => $url,
                    'title' => $this->cleanText((string) $item->title),
                    'excerpt' => $this->cleanText($encodedContent ?: (string) $item->description),
                    'published_at' => $this->parseDate((string) $item->pubDate),
                ];
            }
        }

        if (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $url = (string) ($entry->link['href'] ?? $entry->link);

                if ($url === '') {
                    continue;
                }

                $items[] = [
                    'url' => UrlHelper::cleanUrl($url),
                    'title' => $this->cleanText((string) $entry->title),
                    'excerpt' => $this->cleanText((string) ($entry->summary ?? $entry->content)),
                    'published_at' => $this->parseDate((string) ($entry->published ?? $entry->updated)),
                ];
            }
        }

        return array_slice($this->uniqueByUrl($items), 0, $limit);
    }

    /** @return list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> */
    private function discoverFromHtmlSite(MonitoredSite $site, int $limit): array
    {
        if ($site->article_url_pattern !== null && ! $this->probeService->isValidArticleUrlPattern($site->article_url_pattern)) {
            throw new RuntimeException('Invalid article URL pattern configured for site '.$site->id.': '.$site->name);
        }

        $listingUrl = $site->listing_url ?: $site->base_url;
        $items = [];
        $pages = max(1, min(20, (int) ceil($limit / 25)));

        for ($page = 1; count($items) < $limit && $page <= $pages; $page++) {
            $url = $page === 1 ? $listingUrl : $listingUrl.(str_contains($listingUrl, '?') ? '&' : '?').'page='.$page;

            try {
                $response = Http::withHeaders(UrlHelper::crawlerHeaders())
                    ->withoutVerifying()
                    ->timeout(20)
                    ->retry(
                        [1000, 3000],
                        when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                            || ($exception instanceof RequestException
                                && in_array($exception->response->status(), [429, 502, 503, 504], true)),
                        throw: false,
                    )
                    ->get($url);
            } catch (Throwable $exception) {
                if ($page === 1) {
                    throw new RuntimeException('HTML listing request failed: '.$exception->getMessage(), previous: $exception);
                }

                break;
            }

            if (! $response->successful()) {
                if ($page === 1) {
                    throw new RuntimeException('HTML listing returned HTTP '.$response->status().': '.$url);
                }

                break;
            }

            $items = array_merge($items, $this->extractArticlesFromHtml($response->body(), $url, $site->article_url_pattern));
            $items = $this->uniqueByUrl($items);
        }

        return array_slice($items, 0, $limit);
    }

    /** @return list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> */
    private function extractArticlesFromHtml(string $html, string $baseUrl, ?string $articleUrlPattern): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $items = [];

        foreach ($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-item ")]//a[contains(concat(" ", normalize-space(@class), " "), " article-title ")][@href]') as $node) {
            $url = UrlHelper::absoluteUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null
                || ! UrlHelper::sameHost($url, $baseUrl)
                || ($articleUrlPattern !== null && ! $this->probeService->isArticleUrl($url, $baseUrl, $articleUrlPattern))) {
                continue;
            }

            $items[] = [
                'url' => $url,
                'title' => $this->cleanText($node->getAttribute('title') ?: $node->textContent),
                'excerpt' => null,
                'published_at' => null,
            ];
        }

        if ($items !== []) {
            return $this->uniqueByUrl($items);
        }

        $articleLinks = $this->probeService->extractArticleLinks($html, $baseUrl, $articleUrlPattern);

        foreach ($xpath->query('//a[@href]') as $node) {
            $url = UrlHelper::absoluteUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null || ! UrlHelper::sameHost($url, $baseUrl) || ! in_array($url, $articleLinks, true)) {
                continue;
            }

            $title = $this->cleanText($node->getAttribute('title') ?: $node->textContent);

            $items[] = [
                'url' => $url,
                'title' => $title,
                'excerpt' => null,
                'published_at' => null,
            ];
        }

        return $this->uniqueByUrl($items);
    }

    private function cleanText(mixed $text): ?string
    {
        $text = preg_replace('/<[^>]+>/', ' ', (string) $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');

        return $text === '' ? null : $text;
    }

    private function parseDate(mixed $date): ?CarbonImmutable
    {
        if (! $date) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $date);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> $items */
    private function uniqueByUrl(array $items): array
    {
        $unique = [];

        foreach ($items as $item) {
            $unique[$item['url']] = $item;
        }

        return array_values($unique);
    }
}
