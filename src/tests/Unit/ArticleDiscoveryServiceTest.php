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
