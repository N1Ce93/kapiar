<?php

namespace App\Services\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

class NszuApiClient
{
    private const ORIGIN = 'https://nszu.gov.ua';

    public function supportsListingUrl(string $url): bool
    {
        return $this->supportsHost($url)
            && in_array(rtrim(parse_url($url, PHP_URL_PATH) ?: '', '/'), ['', '/news'], true);
    }

    public function supportsArticleUrl(string $url): bool
    {
        return $this->supportsHost($url)
            && preg_match('~^/news/(?!page-[1-9][0-9]*/?$)[a-z0-9]+(?:-[a-z0-9]+)*/?$~', parse_url($url, PHP_URL_PATH) ?: '') === 1;
    }

    /** @return list<array{url:string,title:string,excerpt:null,published_at:CarbonImmutable}> */
    public function discover(int $limit): array
    {
        $items = [];

        for ($page = 1; count($items) < $limit; $page++) {
            $payload = $this->get('/news', ['page' => $page]);
            $news = $payload['data']['news'] ?? null;

            if (! is_array($news)
                || ($news['current_page'] ?? null) !== $page
                || ! is_int($news['last_page'] ?? null)
                || $news['last_page'] < $page
                || ! is_array($news['data'] ?? null)
                || ! array_is_list($news['data'])) {
                throw new RuntimeException('NSZU API returned invalid news pagination on page '.$page);
            }

            $previousCount = count($items);

            foreach ($news['data'] as $item) {
                if (! is_array($item)
                    || ! is_string($item['url'] ?? null)
                    || ! is_string($item['title'] ?? null)
                    || $this->normalizeText($item['title']) === ''
                    || ! is_string($item['created_at'] ?? null)
                    || trim($item['created_at']) === '') {
                    throw new RuntimeException('NSZU API returned an invalid news item on page '.$page);
                }

                $url = UrlHelper::absoluteUrl($item['url'], self::ORIGIN.'/');

                if ($url === null || ! $this->supportsArticleUrl($url)) {
                    throw new RuntimeException('NSZU API returned an invalid article URL on page '.$page);
                }

                try {
                    $publishedAt = CarbonImmutable::parse($item['created_at']);
                } catch (Throwable $exception) {
                    throw new RuntimeException('NSZU API returned an invalid publication date: '.$url, previous: $exception);
                }

                $url = self::ORIGIN.rtrim(parse_url($url, PHP_URL_PATH), '/');
                $items[$url] = [
                    'url' => $url,
                    'title' => $this->normalizeText($item['title']),
                    'excerpt' => null,
                    'published_at' => $publishedAt,
                ];

                if (count($items) >= $limit) {
                    break;
                }
            }

            if ($page === $news['last_page']) {
                break;
            }

            if (count($items) === $previousCount) {
                throw new RuntimeException('NSZU API pagination made no progress on page '.$page);
            }
        }

        return array_values($items);
    }

    /** @return array{title:string,text:string,hash:string} */
    public function extract(string $url): array
    {
        if (! $this->supportsArticleUrl($url)) {
            throw new RuntimeException('Unsupported NSZU article URL: '.$url);
        }

        $payload = $this->get(rtrim(parse_url($url, PHP_URL_PATH), '/'));
        $news = $payload['data']['news'] ?? null;

        if (! is_array($news)
            || ! is_string($news['title'] ?? null)
            || $this->normalizeText($news['title']) === ''
            || ! is_string($news['content'] ?? null)) {
            throw new RuntimeException('NSZU API returned an invalid article: '.$url);
        }

        try {
            $nodes = json_decode($news['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('NSZU API returned invalid content JSON: '.$url, previous: $exception);
        }

        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new RuntimeException('NSZU API returned an invalid content tree: '.$url);
        }

        $text = $this->normalizeText($this->nodeText($nodes));

        if ($text === '') {
            throw new RuntimeException('NSZU API returned empty article text: '.$url);
        }

        return [
            'title' => $this->normalizeText($news['title']),
            'text' => $text,
            'hash' => hash('sha256', $text),
        ];
    }

    private function get(string $path, array $query = []): array
    {
        $url = self::ORIGIN.'/api'.$path;

        try {
            $response = Http::withHeaders(array_merge(UrlHelper::crawlerHeaders(), [
                'Accept' => 'application/json, text/plain, */*',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-localization' => 'uk',
                'Referer' => self::ORIGIN.'/news',
            ]))
                ->timeout(20)
                ->retry(
                    [1000, 3000],
                    when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && in_array($exception->response->status(), [429, 502, 503, 504], true)),
                    throw: false,
                )
                ->get($url, $query);
        } catch (Throwable $exception) {
            throw new RuntimeException('NSZU API request failed: '.$exception->getMessage(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('NSZU API returned HTTP '.$response->status().': '.$url);
        }

        $payload = $response->json();

        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            throw new RuntimeException('NSZU API returned invalid JSON data: '.$url);
        }

        return $payload;
    }

    private function supportsHost(string $url): bool
    {
        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            && in_array(UrlHelper::host($url), ['nszu.gov.ua', 'www.nszu.gov.ua'], true);
    }

    private function nodeText(array $nodes): string
    {
        $text = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                throw new RuntimeException('NSZU API returned an invalid content node');
            }

            if (($node['type'] ?? null) === 'text' && is_string($node['content'] ?? null)) {
                $text .= $node['content'];

                continue;
            }

            if (($node['type'] ?? null) !== 'element' || ! is_string($node['tagName'] ?? null)) {
                throw new RuntimeException('NSZU API returned an unsupported content node');
            }

            $tag = strtolower($node['tagName']);

            if (in_array($tag, ['script', 'style', 'noscript', 'iframe'], true)) {
                continue;
            }

            $children = $node['children'] ?? [];

            if (! is_array($children) || ! array_is_list($children)) {
                throw new RuntimeException('NSZU API returned invalid content children');
            }

            // Keep inline fragments together (e.g. ЗО<strong>КБ</strong>), but separate blocks.
            $separator = in_array($tag, ['p', 'div', 'section', 'article', 'blockquote', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'br', 'hr', 'tr', 'td', 'th', 'figure', 'figcaption', 'pre'], true) ? ' ' : '';
            $text .= $separator.$this->nodeText($children).$separator;
        }

        return $text;
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
