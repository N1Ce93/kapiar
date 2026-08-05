<?php

namespace App\Services\Monitoring;

class UrlHelper
{
    public static function crawlerHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (compatible; MarketingMonitor/1.0; +https://example.com/monitor)',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/rss+xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'uk-UA,uk;q=0.9,ru;q=0.8,en;q=0.7',
        ];
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '/';

        if ($host === '') {
            return $url;
        }

        $path = '/'.trim($path, '/');
        $path = $path === '/' ? '/' : $path.'/';

        return $scheme.'://'.$host.$path;
    }

    public static function cleanUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! isset($parts['scheme'], $parts['host'])) {
            return trim($url);
        }

        $path = $parts['path'] ?? '/';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = self::cleanQuery($parts['query'] ?? null);

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port.$path.($query ? '?'.$query : '');
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.strtolower($parts['host'] ?? '');
    }

    public static function host(string $url): string
    {
        return strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    }

    public static function sameHost(string $firstUrl, string $secondUrl): bool
    {
        return self::comparableHost($firstUrl) === self::comparableHost($secondUrl);
    }

    public static function absoluteUrl(string $href, string $baseUrl): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return self::cleanUrl($scheme.':'.$href);
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return self::cleanUrl($href);
        }

        $origin = self::origin($baseUrl);

        if (str_starts_with($href, '/')) {
            return self::cleanUrl($origin.$href);
        }

        $path = parse_url($baseUrl, PHP_URL_PATH) ?: '/';
        $directory = rtrim(str_ends_with($path, '/') ? $path : dirname($path), '/');

        return self::cleanUrl($origin.$directory.'/'.$href);
    }

    public static function withoutTrailingSlash(string $url): string
    {
        return rtrim($url, '/');
    }

    private static function comparableHost(string $url): string
    {
        $host = self::host($url);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function cleanQuery(?string $query): string
    {
        if (! $query) {
            return '';
        }

        parse_str($query, $params);

        foreach (array_keys($params) as $key) {
            $normalized = strtolower((string) $key);

            if (str_starts_with($normalized, 'utm_') || in_array($normalized, ['fbclid', 'gclid', 'yclid'], true)) {
                unset($params[$key]);
            }
        }

        ksort($params);

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
