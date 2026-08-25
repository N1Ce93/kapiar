<?php

namespace Tests\Unit;

use App\Services\Monitoring\SiteProbeService;
use Tests\TestCase;

class SiteProbeServiceTest extends TestCase
{
    public function test_configured_pattern_only_extracts_matching_article_urls(): void
    {
        $html = <<<'HTML'
            <html><body>
                <a href="/ua/politika">Політика</a>
                <a href="/ua/news/?rubrika=1">Рубрика</a>
                <a href="/ua/news/page/2">Наступна сторінка</a>
                <a href="/ua/foto/9662">Фото</a>
                <a href="/ua/news/not-numeric/article.html">Без числового ID</a>
                <a href="/ua/news/412842/article">Без розширення</a>
                <a href="/ua/news/412842/nested/article.html">Вкладений шлях</a>
                <a href="/news/412842/russian-article.html">Русская новость</a>
                <a href="https://example.com/ua/news/412842/external.html">Зовнішнє посилання</a>
                <a href="/ua/news/412842/first-article.html">Перша новина</a>
                <a href="/ua/news/412843/druga-novina.html">Друга новина</a>
                <a href="/ua/news/412842/first-article.html">Дублікат</a>
            </body></html>
            HTML;

        $this->assertSame([
            'https://ria-m.tv/ua/news/412842/first-article.html',
            'https://ria-m.tv/ua/news/412843/druga-novina.html',
        ], (new SiteProbeService)->extractArticleLinks(
            $html,
            'https://ria-m.tv/ua/news/',
            '~^/ua/news/[0-9]+/[^/]+\.html$~u',
        ));
    }

    public function test_default_heuristic_is_used_without_a_pattern(): void
    {
        $html = '<html><body><a href="/section/story">Story</a></body></html>';

        $this->assertSame(
            ['https://example.com/section/story'],
            (new SiteProbeService)->extractArticleLinks($html, 'https://example.com/news'),
        );
    }

    public function test_configured_pattern_replaces_the_default_heuristic(): void
    {
        $html = '<html><body><a href="/story.html">Story</a><a href="/section/story">Section</a></body></html>';

        $this->assertSame(
            ['https://example.com/story.html'],
            (new SiteProbeService)->extractArticleLinks($html, 'https://example.com/news', '~^/story\.html$~'),
        );
    }

    public function test_configured_pattern_can_match_the_listing_path_with_a_query(): void
    {
        $html = '<html><body><a href="/news?id=123">Story</a></body></html>';

        $this->assertSame(
            ['https://example.com/news?id=123'],
            (new SiteProbeService)->extractArticleLinks($html, 'https://example.com/news', '~^/news$~'),
        );
    }

    public function test_invalid_runtime_pattern_throws_an_explicit_error(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid article URL pattern');

        (new SiteProbeService)->extractArticleLinks(
            '<html><body><a href="/story.html">Story</a></body></html>',
            'https://example.com/news',
            '[',
        );
    }
}
