<?php

namespace Tests\Feature\Console;

use App\Models\MonitoredSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitesSetContentSelectorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sets_and_clears_a_content_selector(): void
    {
        $site = $this->site();

        $this->artisan('sites:set-content-selector', [
            'site' => (string) $site->id,
            'selector' => '.post > .content',
        ])->assertSuccessful();

        $this->assertSame('.post > .content', $site->fresh()->content_selector);

        $this->artisan('sites:set-content-selector', [
            'site' => (string) $site->id,
            '--clear' => true,
        ])->assertSuccessful();

        $this->assertNull($site->fresh()->content_selector);
    }

    public function test_it_rejects_an_invalid_css_selector(): void
    {
        $site = $this->site();

        $this->artisan('sites:set-content-selector', [
            'site' => (string) $site->id,
            'selector' => '.post[',
        ])->assertFailed();

        $this->assertNull($site->fresh()->content_selector);
    }

    private function site(): MonitoredSite
    {
        return MonitoredSite::create([
            'name' => 'MedInfo',
            'base_url' => 'https://medinfo.zp.ua/',
            'source_type' => 'rss',
            'feed_url' => 'https://medinfo.zp.ua/feed/',
            'enabled' => true,
        ]);
    }
}
