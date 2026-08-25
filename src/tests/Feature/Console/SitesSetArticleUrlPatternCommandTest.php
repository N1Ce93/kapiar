<?php

namespace Tests\Feature\Console;

use App\Models\MonitoredSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitesSetArticleUrlPatternCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_and_clears_an_article_url_pattern(): void
    {
        $site = $this->site();
        $pattern = '~^/ua/news/[0-9]+/[^/]+\.html$~u';

        $this->artisan('sites:set-article-url-pattern', [
            'id' => (string) $site->id,
            'pattern' => $pattern,
        ])->assertSuccessful();

        $this->assertSame($pattern, $site->fresh()->article_url_pattern);

        $this->artisan('sites:set-article-url-pattern', [
            'id' => (string) $site->id,
            '--clear' => true,
        ])->assertSuccessful();

        $this->assertNull($site->fresh()->article_url_pattern);
    }

    public function test_it_rejects_invalid_patterns_and_arguments(): void
    {
        $site = $this->site();

        $this->artisan('sites:set-article-url-pattern', [
            'id' => (string) $site->id,
            'pattern' => '[',
        ])->assertFailed();

        $this->artisan('sites:set-article-url-pattern', [
            'id' => (string) $site->id,
            'pattern' => '~valid~',
            '--clear' => true,
        ])->assertFailed();

        $this->artisan('sites:set-article-url-pattern', ['id' => 'invalid', '--clear' => true])
            ->assertFailed();

        $this->artisan('sites:set-article-url-pattern', ['id' => '999', '--clear' => true])
            ->assertFailed();

        $this->assertNull($site->fresh()->article_url_pattern);
    }

    public function test_it_rejects_setting_a_pattern_for_an_rss_site(): void
    {
        $site = MonitoredSite::create([
            'name' => 'Example RSS',
            'base_url' => 'https://example.com/',
            'source_type' => 'rss',
            'feed_url' => 'https://example.com/feed.xml',
            'enabled' => true,
        ]);

        $this->artisan('sites:set-article-url-pattern', [
            'id' => (string) $site->id,
            'pattern' => '~^/news/~',
        ])->assertFailed();

        $this->assertNull($site->fresh()->article_url_pattern);
    }

    private function site(): MonitoredSite
    {
        return MonitoredSite::create([
            'name' => 'Example News',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'listing_url' => 'https://example.com/news',
            'enabled' => true,
        ]);
    }
}
