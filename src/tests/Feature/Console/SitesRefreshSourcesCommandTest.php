<?php

namespace Tests\Feature\Console;

use App\Models\MonitoredSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitesRefreshSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_switching_to_rss_clears_the_article_url_pattern(): void
    {
        $site = MonitoredSite::create([
            'name' => 'Example',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'listing_url' => 'https://example.com/news',
            'article_url_pattern' => '~^/news/[0-9]+/[^/]+\.html$~',
            'enabled' => true,
        ]);
        Http::fake(['*' => Http::response(
            '<?xml version="1.0"?><rss version="2.0"><channel><item><title>Article</title></item></channel></rss>',
            200,
        )]);

        $this->artisan('sites:refresh-sources', ['--site' => (string) $site->id, '--dry-run' => true])
            ->expectsTable(['Field', 'Current', 'Detected'], [
                ['Source', 'html', 'rss'],
                ['RSS URL', '-', 'https://example.com/feed/'],
                ['HTML listing URL', 'https://example.com/news', '-'],
                ['Article URL pattern', '~^/news/[0-9]+/[^/]+\.html$~', '-'],
                ['HTML article links', '-', '0'],
            ])
            ->assertSuccessful();

        $this->assertSame('html', $site->fresh()->source_type);
        $this->assertNotNull($site->fresh()->article_url_pattern);

        $this->artisan('sites:refresh-sources', ['--site' => (string) $site->id])
            ->assertSuccessful();

        $site->refresh();
        $this->assertSame('rss', $site->source_type);
        $this->assertNull($site->listing_url);
        $this->assertNull($site->article_url_pattern);
    }
}
