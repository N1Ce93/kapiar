<?php

namespace Tests\Feature\Console;

use App\Models\MonitoredSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitesEnableCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enables_site_by_id_and_clears_disabled_state(): void
    {
        $site = MonitoredSite::create([
            'name' => 'Example News',
            'base_url' => 'https://example.com/',
            'source_type' => 'rss',
            'feed_url' => 'https://example.com/feed.xml',
            'enabled' => false,
            'consecutive_failures' => 4,
            'last_checked_at' => '2026-08-10 10:00:00',
            'last_backfilled_at' => '2026-08-09 10:00:00',
            'last_queued_at' => '2026-08-10 09:50:00',
            'last_success_at' => '2026-08-08 10:00:00',
            'last_error_at' => '2026-08-10 10:00:00',
            'last_error' => 'Connection failed',
            'disabled_at' => '2026-08-10 10:00:00',
            'disabled_reason' => 'auto-disabled after 4 consecutive failures',
        ]);

        $this->artisan('sites:enable', ['site' => (string) $site->id])
            ->expectsOutput("Site enabled: Example News (ID: {$site->id})")
            ->assertSuccessful();

        $site->refresh();

        $this->assertTrue($site->enabled);
        $this->assertSame(0, $site->consecutive_failures);
        $this->assertNull($site->last_error_at);
        $this->assertNull($site->last_error);
        $this->assertNull($site->disabled_at);
        $this->assertNull($site->disabled_reason);
        $this->assertSame('2026-08-10 10:00:00', $site->last_checked_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-09 10:00:00', $site->last_backfilled_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-10 09:50:00', $site->last_queued_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-08 10:00:00', $site->last_success_at?->format('Y-m-d H:i:s'));
        $this->assertSame('rss', $site->source_type);
        $this->assertSame('https://example.com/feed.xml', $site->feed_url);
    }

    public function test_it_enables_site_by_normalized_url(): void
    {
        $site = MonitoredSite::create([
            'name' => 'Example News',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'enabled' => false,
        ]);

        $this->artisan('sites:enable', ['site' => 'HTTPS://example.com'])
            ->assertSuccessful();

        $this->assertTrue($site->fresh()->enabled);
    }

    public function test_it_does_not_clear_errors_when_site_is_already_enabled(): void
    {
        $site = MonitoredSite::create([
            'name' => 'Example News',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'enabled' => true,
            'consecutive_failures' => 2,
            'last_error' => 'Temporary error',
        ]);

        $this->artisan('sites:enable', ['site' => (string) $site->id])
            ->expectsOutput("Site is already enabled: Example News (ID: {$site->id})")
            ->assertSuccessful();

        $site->refresh();

        $this->assertSame(2, $site->consecutive_failures);
        $this->assertSame('Temporary error', $site->last_error);
    }

    public function test_it_fails_for_invalid_or_unknown_site(): void
    {
        $this->artisan('sites:enable', ['site' => 'not a url'])
            ->expectsOutput('Invalid site identifier. Use a positive ID or a valid HTTP(S) URL.')
            ->assertFailed();

        $this->artisan('sites:enable', ['site' => '999'])
            ->expectsOutput('Site not found: 999')
            ->assertFailed();
    }
}
