<?php

namespace App\Services\Monitoring;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SiteProbeService
{
    public function probe(string $url): array
    {
        $baseUrl = UrlHelper::normalizeBaseUrl($url);
        $feedUrl = $this->detectFeedUrl($baseUrl);
        $html = $this->detectHtmlListingUrl($baseUrl);

        return [
            'base_url' => $baseUrl,
            'name' => UrlHelper::host($baseUrl),
            'source_type' => $feedUrl ? 'rss' : ($html['listing_url'] ? 'html' : null),
            'feed_url' => $feedUrl,
            'listing_url' => $html['listing_url'],
            'html_article_count' => $html['count'],
        ];
    }

    public function detectFeedUrl(string $baseUrl): ?string
    {
        $html = $this->getBody($baseUrl);
        $candidates = [];

        if ($html !== null) {
            $candidates = array_merge($candidates, $this->extractFeedLinks($html, $baseUrl));
        }

        $candidates = array_merge($candidates, $this->standardFeedCandidates($baseUrl));

        foreach (array_unique($candidates) as $candidate) {
            if ($this->isValidFeed($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function detectHtmlListingUrl(string $baseUrl): array
    {
        $candidates = array_unique([
            $baseUrl,
            UrlHelper::origin($baseUrl).'/news',
            UrlHelper::origin($baseUrl).'/novosti',
            UrlHelper::origin($baseUrl).'/new',
        ]);

        foreach ($candidates as $candidate) {
            $html = $this->getBody($candidate);

            if ($html === null) {
                continue;
            }

            $count = count($this->extractArticleLinks($html, $candidate));

            if ($count > 0) {
                return ['listing_url' => $candidate, 'count' => $count];
            }
        }

        return ['listing_url' => null, 'count' => 0];
    }

    /** @return list<string> */
    public function extractArticleLinks(string $html, string $baseUrl, ?string $articleUrlPattern = null): array
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);
        $links = [];

        foreach ($xpath->query('//a[@href]') as $node) {
            $url = UrlHelper::absoluteUrl($node->getAttribute('href'), $baseUrl);

            if ($url === null || ! $this->isArticleUrl($url, $baseUrl, $articleUrlPattern)) {
                continue;
            }

            $links[] = $url;
        }

        return array_values(array_unique($links));
    }

    public function isValidArticleUrlPattern(string $pattern): bool
    {
        return $pattern !== '' && mb_strlen($pattern) <= 1000 && @preg_match($pattern, '/') !== false;
    }

    public function isArticleUrl(string $url, string $baseUrl, ?string $articleUrlPattern = null): bool
    {
        if (! UrlHelper::sameHost($url, $baseUrl)) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $trimmedPath = trim($path, '/');
        $basePath = trim(parse_url($baseUrl, PHP_URL_PATH) ?: '', '/');

        if ($trimmedPath === '') {
            return false;
        }

        if (preg_match('~\.(jpg|jpeg|png|gif|svg|webp|css|js|pdf|zip)$~i', $trimmedPath)) {
            return false;
        }

        if ($articleUrlPattern !== null) {
            $matches = @preg_match($articleUrlPattern, $path);

            if ($matches === false) {
                throw new RuntimeException('Invalid article URL pattern: '.preg_last_error_msg());
            }

            return $matches === 1;
        }

        if ($trimmedPath === $basePath) {
            return false;
        }

        if (preg_match('~^(category|tag|author|page|wp-content|wp-json|feed|comments|search|login|register|firms|afisha)(/|$)~i', $trimmedPath)) {
            return false;
        }

        return str_contains($trimmedPath, '/') || preg_match('~\d{4}|new/|news/view~i', $trimmedPath) === 1;
    }

    private function isValidFeed(string $url): bool
    {
        $body = $this->getBody($url);

        if ($body === null) {
            return false;
        }

        try {
            $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        } catch (Throwable) {
            return false;
        }

        if (! $xml) {
            return false;
        }

        return isset($xml->channel->item) || isset($xml->entry);
    }

    /** @return list<string> */
    private function extractFeedLinks(string $html, string $baseUrl): array
    {
        $dom = $this->loadHtml($html);
        $xpath = new DOMXPath($dom);
        $feeds = [];

        foreach ($xpath->query('//link[@href]') as $node) {
            $type = strtolower($node->getAttribute('type'));
            $rel = strtolower($node->getAttribute('rel'));

            if (! str_contains($rel, 'alternate') || (! str_contains($type, 'rss') && ! str_contains($type, 'atom') && ! str_contains($type, 'xml'))) {
                continue;
            }

            $url = UrlHelper::absoluteUrl($node->getAttribute('href'), $baseUrl);

            if ($url !== null) {
                $feeds[] = $url;
            }
        }

        return $feeds;
    }

    /** @return list<string> */
    private function standardFeedCandidates(string $baseUrl): array
    {
        $base = UrlHelper::withoutTrailingSlash($baseUrl);
        $origin = UrlHelper::origin($baseUrl);

        return [
            $base.'/feed/',
            $base.'/feed',
            $base.'/rss',
            $base.'/rss.xml',
            $base.'/news/rss',
            $origin.'/feed/',
            $origin.'/rss',
            $origin.'/rss.xml',
        ];
    }

    private function getBody(string $url): ?string
    {
        try {
            $response = Http::withHeaders(UrlHelper::crawlerHeaders())
                ->withoutVerifying()
                ->timeout(20)
                ->retry(1, 500)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    private function loadHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }
}
