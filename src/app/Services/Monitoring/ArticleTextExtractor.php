<?php

namespace App\Services\Monitoring;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

class ArticleTextExtractor
{
    /** @return array{title:?string,text:string,hash:string}|null */
    public function extract(string $url): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => 'MarketingMonitor/1.0'])
                ->timeout(20)
                ->retry(1, 500)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->body(), LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $title = $this->metaContent($xpath, 'og:title') ?: $this->nodeText($xpath, '//h1') ?: $this->nodeText($xpath, '//title');

        foreach ($xpath->query('//script|//style|//noscript|//svg|//nav|//header|//footer|//aside|//form') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $xpath->query('//article')->item(0) ?: $xpath->query('//main')->item(0) ?: $xpath->query('//body')->item(0);
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
}
