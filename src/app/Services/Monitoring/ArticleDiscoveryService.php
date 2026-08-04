<?php

namespace App\Services\Monitoring;

use App\Models\MonitoredSite;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

class ArticleDiscoveryService
{
    public function __construct(private readonly SiteProbeService $probeService)
    {
    }

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
        $fromApi = $this->discoverFromWordPressApi($site, $limit);

        if ($fromApi !== []) {
            return $fromApi;
        }

        return $this->discoverFromFeed($site->feed_url, $limit);
    }

    /** @return list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> */
    private function discoverFromWordPressApi(MonitoredSite $site, int $limit): array
    {
        $apiBase = UrlHelper::withoutTrailingSlash($site->base_url).'/wp-json/wp/v2/posts';
        $items = [];
        $perPage = min(100, max(1, $limit));

        for ($page = 1; count($items) < $limit && $page <= 20; $page++) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'MarketingMonitor/1.0'])
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
            $response = Http::withHeaders(['User-Agent' => 'MarketingMonitor/1.0'])
                ->timeout(20)
                ->retry(1, 500)
                ->get($feedUrl);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        try {
            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);
        } catch (Throwable) {
            return [];
        }

        if (! $xml) {
            return [];
        }

        $items = [];

        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $url = UrlHelper::cleanUrl((string) $item->link);

                if ($url === '') {
                    continue;
                }

                $items[] = [
                    'url' => $url,
                    'title' => $this->cleanText((string) $item->title),
                    'excerpt' => $this->cleanText((string) $item->description),
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
        $listingUrl = $site->listing_url ?: $site->base_url;
        $items = [];
        $pages = max(1, min(20, (int) ceil($limit / 25)));

        for ($page = 1; count($items) < $limit && $page <= $pages; $page++) {
            $url = $page === 1 ? $listingUrl : $listingUrl.(str_contains($listingUrl, '?') ? '&' : '?').'page='.$page;

            try {
                $response = Http::withHeaders(['User-Agent' => 'MarketingMonitor/1.0'])
                    ->timeout(20)
                    ->retry(1, 500)
                    ->get($url);
            } catch (Throwable) {
                break;
            }

            if (! $response->successful()) {
                break;
            }

            $items = array_merge($items, $this->extractArticlesFromHtml($response->body(), $url));
            $items = $this->uniqueByUrl($items);
        }

        return array_slice($items, 0, $limit);
    }

    /** @return list<array{url:string,title:?string,excerpt:?string,published_at:?CarbonImmutable}> */
    private function extractArticlesFromHtml(string $html, string $baseUrl): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $baseHost = UrlHelper::host($baseUrl);
        $articleLinks = $this->probeService->extractArticleLinks($html, $baseUrl);
        $items = [];

        foreach ($xpath->query('//a[@href]') as $node) {
            $url = UrlHelper::absoluteUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null || UrlHelper::host($url) !== $baseHost || ! in_array($url, $articleLinks, true)) {
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
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');

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
