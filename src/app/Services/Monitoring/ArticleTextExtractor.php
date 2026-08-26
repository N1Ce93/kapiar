<?php

namespace App\Services\Monitoring;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

class ArticleTextExtractor
{
    /** @return array{title:?string,text:string,hash:string}|null */
    public function extract(string $url, ?string $contentSelector = null): ?array
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

        if (! $response->successful()) {
            return null;
        }

        $html = $this->utf8Html($response);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $title = $this->metaContent($xpath, 'og:title') ?: $this->nodeText($xpath, '//h1') ?: $this->nodeText($xpath, '//title');
        $configuredBody = $this->configuredContentNode($xpath, $url, $contentSelector);

        if ($configuredBody) {
            $this->removeNoise($xpath, $configuredBody);
            $body = $configuredBody;
        } else {
            $this->removeNoise($xpath);
            $body = $xpath->query('//article')->item(0)
                ?: $xpath->query('//main')->item(0)
                ?: $xpath->query('//body')->item(0);
        }
        $text = $this->normalizeText($body?->textContent ?? '');

        if ($text === '') {
            return null;
        }

        return [
            'title' => $this->normalizeText($title ?? '') ?: null,
            'text' => $text,
            'hash' => hash('sha256', $text),
        ];
    }

    private function configuredContentNode(DOMXPath $xpath, string $url, ?string $selector): ?DOMNode
    {
        $selector = trim((string) $selector);

        if ($selector === '') {
            return null;
        }

        try {
            $query = (new CssSelectorConverter)->toXPath($selector);
            $nodes = $xpath->query($query);
            $node = $nodes === false ? null : $nodes->item(0);
        } catch (Throwable $exception) {
            Log::warning('Configured article content selector is invalid; using default extraction.', [
                'url' => $url,
                'selector' => $selector,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $node) {
            Log::warning('Configured article content selector did not match; using default extraction.', [
                'url' => $url,
                'selector' => $selector,
            ]);
        }

        return $node;
    }

    private function metaContent(DOMXPath $xpath, string $property): ?string
    {
        $node = $xpath->query('//meta[@property="'.$property.'"]/@content')->item(0);

        return $node ? $this->normalizeText($node->nodeValue) : null;
    }

    private function nodeText(DOMXPath $xpath, string $query): ?string
    {
        $node = $xpath->query($query)->item(0);

        return $node ? $this->normalizeText($node->textContent) : null;
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
    }

    private function removeNoise(DOMXPath $xpath, ?DOMNode $scope = null): void
    {
        $query = ($scope ? './/' : '//').'script|'.($scope ? './/' : '//').'style|'.($scope ? './/' : '//').'noscript|'
            .($scope ? './/' : '//').'svg|'.($scope ? './/' : '//').'nav|'.($scope ? './/' : '//').'header|'
            .($scope ? './/' : '//').'footer|'.($scope ? './/' : '//').'aside|'.($scope ? './/' : '//').'form';

        foreach ($xpath->query($query, $scope) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function utf8Html(Response $response): string
    {
        $html = $response->body();
        $sample = (string) $response->header('Content-Type').' '.substr($html, 0, 4096);

        if (! preg_match('~charset\s*=\s*["\']?([a-z0-9._-]+)~i', $sample, $match)) {
            return $html;
        }

        $encoding = strtolower($match[1]);

        if (in_array($encoding, ['utf-8', 'utf8'], true)) {
            return $html;
        }

        try {
            return mb_convert_encoding($html, 'UTF-8', $encoding);
        } catch (Throwable) {
            return $html;
        }
    }
}
