<?php

namespace Tests\Feature\Console;

use App\Models\MonitoredSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitesAddCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_an_article_url_pattern(): void
    {
        Http::fake(['*' => Http::response('<html><body><a href="/ua/news/1/article.html">Article</a></body></html>', 200)]);
        $pattern = '~^/ua/news/[0-9]+/[^/]+\.html$~u';

        $this->artisan('sites:add', [
            'url' => 'https://ria-m.tv/ua/',
            '--name' => 'РИА Мелитополь',
            '--source' => 'html',
            '--listing-url' => 'https://ria-m.tv/ua/news/',
            '--article-url-pattern' => $pattern,
            '--no-backfill' => true,
        ])->assertSuccessful();

        $site = MonitoredSite::where('base_url', 'https://ria-m.tv/ua/')->sole();
        $this->assertSame($pattern, $site->article_url_pattern);
    }

    public function test_updating_without_the_option_preserves_the_existing_pattern(): void
    {
        $pattern = '~^/news/[0-9]+/[^/]+\.html$~';
        $site = MonitoredSite::create([
            'name' => 'Example',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'listing_url' => 'https://example.com/news',
            'article_url_pattern' => $pattern,
            'enabled' => true,
        ]);
        Http::fake(['*' => Http::response('<html><body><a href="/news/1/article.html">Article</a></body></html>', 200)]);

        $this->artisan('sites:add', [
            'url' => 'https://example.com/',
            '--source' => 'html',
            '--listing-url' => 'https://example.com/news',
            '--no-backfill' => true,
        ])->assertSuccessful();

        $this->assertSame($pattern, $site->fresh()->article_url_pattern);
    }

    public function test_it_rejects_an_invalid_pattern_before_probing(): void
    {
        Http::fake();

        $this->artisan('sites:add', [
            'url' => 'https://example.com/',
            '--source' => 'html',
            '--listing-url' => 'https://example.com/news',
            '--article-url-pattern' => '[',
            '--no-backfill' => true,
        ])->assertFailed();

        Http::assertNothingSent();
        $this->assertDatabaseCount('monitored_sites', 0);
    }

    public function test_it_rejects_a_pattern_for_an_rss_source_before_probing(): void
    {
        Http::fake();

        $this->artisan('sites:add', [
            'url' => 'https://example.com/',
            '--source' => 'rss',
            '--feed-url' => 'https://example.com/feed.xml',
            '--article-url-pattern' => '~^/news/~',
            '--no-backfill' => true,
        ])->assertFailed();

        Http::assertNothingSent();
        $this->assertDatabaseCount('monitored_sites', 0);
    }
}
