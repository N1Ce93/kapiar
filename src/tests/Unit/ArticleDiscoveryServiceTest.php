<?php

namespace Tests\Unit;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleDiscoveryService;
use App\Services\Monitoring\SiteProbeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class ArticleDiscoveryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    public function test_html_discovery_retries_temporary_http_errors(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->push('', 504)
            ->push('', 503)
            ->push('<html><body></body></html>', 200);

        $this->assertSame([], $this->service()->discover($this->site(), 25));

        Http::assertSentCount(3);
        Sleep::assertSequence([
            Sleep::for(1)->second(),
            Sleep::for(3)->seconds(),
        ]);
    }

    public function test_html_discovery_retries_connection_errors(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->pushFailedConnection('Connection reset')
            ->push('<html><body></body></html>', 200);

        $this->assertSame([], $this->service()->discover($this->site(), 25));

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    public function test_html_discovery_does_not_retry_permanent_http_errors(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response('', 404)]);

        try {
            $this->service()->discover($this->site(), 25);
            $this->fail('Expected HTML discovery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'HTML listing returned HTTP 404: https://example.com/news',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_ria_discovery_only_returns_ukrainian_article_urls(): void
    {
        Http::fake(['*' => Http::response(<<<'HTML'
            <html><body>
                <a href="/ua/politika">Політика</a>
                <a href="/ua/region">Запоріжжя</a>
                <a href="/ua/news/page/2">Наступна сторінка</a>
                <a href="/news/412842/russian-article.html">Русская новость</a>
                <a href="/ua/news/412842/ukrainian-article.html"><img src="article.jpg"></a>
                <a href="/ua/news/412842/ukrainian-article.html">Українська новина</a>
            </body></html>
            HTML, 200)]);

        $items = $this->service()->discover($this->riaSite(), 25);

        $this->assertCount(1, $items);
        $this->assertSame('https://ria-m.tv/ua/news/412842/ukrainian-article.html', $items[0]['url']);
        $this->assertSame('Українська новина', $items[0]['title']);
    }

    public function test_ria_rules_also_apply_to_optimized_article_markup(): void
    {
        Http::fake(['*' => Http::response(<<<'HTML'
            <html><body>
                <div class="article-item">
                    <a class="article-title" href="/ua/politika">Політика</a>
                </div>
                <div class="article-item">
                    <a class="article-title" href="/ua/news/412842/ukrainian-article.html">Українська новина</a>
                </div>
            </body></html>
            HTML, 200)]);

        $items = $this->service()->discover($this->riaSite(), 25);

        $this->assertCount(1, $items);
        $this->assertSame('https://ria-m.tv/ua/news/412842/ukrainian-article.html', $items[0]['url']);
    }

    public function test_optimized_markup_keeps_previous_behavior_without_a_pattern(): void
    {
        Http::fake(['*' => Http::response(<<<'HTML'
            <html><body>
                <div class="article-item">
                    <a class="article-title" href="/story">Story</a>
                </div>
            </body></html>
            HTML, 200)]);

        $items = $this->service()->discover($this->site(), 25);

        $this->assertCount(1, $items);
        $this->assertSame('https://example.com/story', $items[0]['url']);
    }

    public function test_invalid_stored_pattern_fails_before_requesting_the_listing(): void
    {
        Http::fake();
        $site = $this->site();
        $site->article_url_pattern = '[';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid article URL pattern configured for site');

        try {
            $this->service()->discover($site, 25);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_rss_discovery_prefers_full_encoded_content_over_description(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
            <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">
                <channel><item>
                    <title>Операція</title>
                    <link>https://example.com/news/operation</link>
                    <description>Короткий опис.</description>
                    <content:encoded><![CDATA[<p>Повний текст.</p><p>Лікарі провели операцію.</p>]]></content:encoded>
                </item></channel>
            </rss>
            XML, 200)]);

        $items = $this->service()->discover($this->rssSite(), 25);

        $this->assertSame('Повний текст. Лікарі провели операцію.', $items[0]['excerpt']);
    }

    public function test_rss_discovery_recovers_html_entities_in_windows_1251_without_changing_encoding(): void
    {
        $body = mb_convert_encoding(<<<'XML'
            <?xml version="1.0" encoding="Windows-1251"?>
            <rss version="2.0"><channel><item>
                <title>Як вибрати платформу &bull; реклама</title>
                <link>https://example.com/news/platform</link>
                <description>Новини &mdash; Запоріжжя</description>
            </item></channel></rss>
            XML, 'Windows-1251', 'UTF-8');
        Http::fake(['*' => Http::response($body, 200)]);

        $items = $this->service()->discover($this->rssSite());

        $this->assertCount(1, $items);
        $this->assertSame('Як вибрати платформу • реклама', $items[0]['title']);
        $this->assertSame('Новини — Запоріжжя', $items[0]['excerpt']);
        Http::assertSentCount(1);
    }

    public function test_rss_discovery_recovers_html_entities_without_double_decoding_xml_entities(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
            <rss version="2.0"><channel><item>
                <title>Новини &bull; &copy; &euro; &lpar;2026&rpar; &NotEqualTilde;</title>
                <link>https://example.com/story?a=1&amp;b=2</link>
                <description>&lt;b&gt;Текст&lt;/b&gt; &amp; &quot;цитата&quot; &apos;так&apos; &#8226; &#x2022; &nbsp; кінець</description>
            </item></channel></rss>
            XML, 200)]);

        $items = $this->service()->discover($this->rssSite());

        $this->assertSame("Новини • © € (2026) \u{2242}\u{0338}", $items[0]['title']);
        $this->assertSame('https://example.com/story?a=1&b=2', $items[0]['url']);
        $this->assertSame('Текст & "цитата" \'так\' • • кінець', $items[0]['excerpt']);
    }

    public function test_rss_recovery_does_not_rewrite_entities_inside_cdata(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
            <rss version="2.0"><channel><item>
                <title>Новина &bull; реклама</title>
                <link><![CDATA[https://example.com/&bull;]]></link>
                <description><![CDATA[<p>Текст &bull; новини</p>]]></description>
            </item></channel></rss>
            XML, 200)]);

        $items = $this->service()->discover($this->rssSite());

        $this->assertSame('https://example.com/&bull;', $items[0]['url']);
        $this->assertSame('Текст • новини', $items[0]['excerpt']);
    }

    public function test_rss_recovery_preserves_entities_declared_in_the_feed(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
            <!DOCTYPE rss [<!ENTITY bull "Custom bullet">]>
            <rss version="2.0"><channel><item>
                <title>&bull; &mdash; новина</title>
                <link>https://example.com/story</link>
            </item></channel></rss>
            XML, 200)]);

        $items = $this->service()->discover($this->rssSite());

        $this->assertSame('Custom bullet — новина', $items[0]['title']);
    }

    public function test_valid_rss_preserves_xml_entities_cdata_and_dates(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><item>
                <title>Новини &amp; події &#8226; &#x2022;</title>
                <link>https://example.com/story?a=1&amp;b=2</link>
                <description><![CDATA[<p>Текст &copy; 2026</p>]]></description>
                <pubDate>Tue, 24 Nov 2026 08:00:00 +0200</pubDate>
            </item></channel></rss>
            XML, 200)]);

        $items = $this->service()->discover($this->rssSite());

        $this->assertSame('Новини & події • •', $items[0]['title']);
        $this->assertSame('https://example.com/story?a=1&b=2', $items[0]['url']);
        $this->assertSame('Текст © 2026', $items[0]['excerpt']);
        $this->assertSame('2026-11-24T08:00:00+02:00', $items[0]['published_at']->toIso8601String());
    }

    public function test_valid_atom_feed_is_still_discovered(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
            <feed xmlns="http://www.w3.org/2005/Atom">
                <entry>
                    <title>Новини &amp; події</title>
                    <link href="https://example.com/story"/>
                    <summary>Короткий опис.</summary>
                    <updated>2026-11-24T08:00:00Z</updated>
                </entry>
            </feed>
            XML, 200)]);

        $items = $this->service()->discover($this->rssSite());

        $this->assertCount(1, $items);
        $this->assertSame('Новини & події', $items[0]['title']);
        $this->assertSame('https://example.com/story', $items[0]['url']);
        $this->assertSame('Короткий опис.', $items[0]['excerpt']);
        $this->assertSame('2026-11-24T08:00:00+00:00', $items[0]['published_at']->toIso8601String());
    }

    public function test_atom_feed_can_also_recover_html_entities(): void
    {
        Http::fake(['*' => Http::response(<<<'XML'
            <feed xmlns="http://www.w3.org/2005/Atom">
                <entry>
                    <title>Новини &bull; події</title>
                    <link href="https://example.com/story"/>
                </entry>
            </feed>
            XML, 200)]);

        $items = $this->service()->discover($this->rssSite());

        $this->assertSame('Новини • події', $items[0]['title']);
    }

    public function test_unknown_entities_are_rejected_with_xml_diagnostics(): void
    {
        Http::fake(['*' => Http::response(
            '<rss><channel><title>&unknownEntity;</title></channel></rss>', 200,
        )]);

        try {
            $this->service()->discover($this->rssSite());
            $this->fail('Expected an unknown XML entity to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('RSS feed could not be parsed: https://example.com/feed/', $exception->getMessage());
            $this->assertStringContainsString("Entity 'unknownEntity' not defined", $exception->getMessage());
            $this->assertStringContainsString('line 1', $exception->getMessage());
        }
    }

    public function test_html_entity_recovery_does_not_accept_otherwise_malformed_xml(): void
    {
        Http::fake(['*' => Http::response(
            '<rss><channel><title>Новини &bull;</title></rss>', 200,
        )]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Opening and ending tag mismatch');

        $this->service()->discover($this->rssSite());
    }

    public function test_empty_valid_rss_returns_no_articles(): void
    {
        Http::fake(['*' => Http::response('<rss/>', 200)]);

        $this->assertSame([], $this->service()->discover($this->rssSite()));
    }

    public function test_rss_parsing_restores_libxml_error_handling_after_success_and_failure(): void
    {
        $previous = libxml_use_internal_errors();

        try {
            foreach ([false, true] as $internalErrors) {
                foreach (['<rss/>', '<rss><title>&bull;</title></rss>', '<rss>'] as $body) {
                    libxml_use_internal_errors($internalErrors);
                    Http::fake(['*' => Http::response($body, 200)]);

                    try {
                        $this->service()->discover($this->rssSite());
                    } catch (RuntimeException) {
                        $this->assertSame('<rss>', $body);
                    }

                    $this->assertSame($internalErrors, libxml_use_internal_errors());
                    $this->assertSame([], libxml_get_errors());
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function service(): ArticleDiscoveryService
    {
        return new ArticleDiscoveryService(new SiteProbeService);
    }

    private function site(): MonitoredSite
    {
        return new MonitoredSite([
            'name' => 'Example',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'listing_url' => 'https://example.com/news',
            'enabled' => true,
        ]);
    }

    private function riaSite(): MonitoredSite
    {
        return new MonitoredSite([
            'name' => 'РИА Мелитополь',
            'base_url' => 'https://ria-m.tv/ua/',
            'source_type' => 'html',
            'listing_url' => 'https://ria-m.tv/ua/news/',
            'article_url_pattern' => '~^/ua/news/[0-9]+/[^/]+\.html$~u',
            'enabled' => true,
        ]);
    }

    private function rssSite(): MonitoredSite
    {
        return new MonitoredSite([
            'name' => 'RSS Example',
            'base_url' => 'https://example.com/',
            'source_type' => 'rss',
            'feed_url' => 'https://example.com/feed/',
            'enabled' => true,
        ]);
    }
}
