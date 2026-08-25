<?php

namespace Tests\Unit;

use App\Services\Monitoring\ArticleTextExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleTextExtractorTest extends TestCase
{
    public function test_it_uses_the_configured_content_selector(): void
    {
        Http::fake(['*' => Http::response($this->html(
            '<div class="post"><div class="content">Основной текст о вакцинации.</div></div>
            <section class="related">Читайте также: операция в ЗОКБ.</section>',
        ))]);

        $article = (new ArticleTextExtractor)->extract('https://medinfo.zp.ua/vaccine/', '.post > .content');

        $this->assertSame('Основной текст о вакцинации.', $article['text']);
        $this->assertStringNotContainsString('ЗОКБ', $article['text']);
    }

    public function test_it_keeps_a_keyword_that_is_inside_the_configured_content(): void
    {
        Http::fake(['*' => Http::response($this->html(
            '<div class="post"><div class="content">Врачи ЗОКБ провели операцию.</div></div>',
        ))]);

        $article = (new ArticleTextExtractor)->extract('https://medinfo.zp.ua/operation/', '.post > .content');

        $this->assertStringContainsString('ЗОКБ', $article['text']);
    }

    public function test_it_uses_the_default_flow_when_the_selector_does_not_match(): void
    {
        Http::fake(['*' => Http::response($this->html(
            '<main>Основной текст. <section>Читайте также: операция в ЗОКБ.</section></main>',
        ))]);

        $article = (new ArticleTextExtractor)->extract('https://example.com/vaccine/', '.missing');

        $this->assertStringContainsString('ЗОКБ', $article['text']);
    }

    private function html(string $body): string
    {
        return '<html><head><meta property="og:title" content="Заголовок"></head><body>'.$body.'</body></html>';
    }
}
