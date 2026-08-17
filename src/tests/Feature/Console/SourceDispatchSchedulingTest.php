<?php

namespace Tests\Feature\Console;

use App\Jobs\CheckMonitoredSiteJob;
use App\Jobs\CheckTelegramChannelJob;
use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SourceDispatchSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(CheckTelegramChannelJob::CIRCUIT_CACHE_KEY);

        parent::tearDown();
    }

    public function test_site_dispatch_selects_only_due_sources_and_reserves_30_minutes(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        Queue::fake();
        $due = $this->site('Due', 'https://due.example.com', now()->subMinute());
        $unscheduled = $this->site('Unscheduled', 'https://new.example.com');
        $future = $this->site('Future', 'https://future.example.com', now()->addMinute());

        $this->artisan('sources:dispatch-checks')->assertSuccessful();

        Queue::assertPushed(CheckMonitoredSiteJob::class, 2);
        Queue::assertPushed(fn (CheckMonitoredSiteJob $job): bool => $job->siteId === $due->id);
        Queue::assertPushed(fn (CheckMonitoredSiteJob $job): bool => $job->siteId === $unscheduled->id);
        Queue::assertNotPushed(fn (CheckMonitoredSiteJob $job): bool => $job->siteId === $future->id);
        $this->assertTrue($due->fresh()->next_check_at->equalTo(now()->subMinute()));
        $this->assertNotNull($due->fresh()->check_pending_at);
        $this->assertNull($unscheduled->fresh()->next_check_at);
        $this->assertNotNull($unscheduled->fresh()->check_pending_at);
        $this->assertTrue($future->fresh()->next_check_at->equalTo(now()->addMinute()));
    }

    public function test_explicit_site_dispatch_bypasses_future_next_check_date(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        Queue::fake();
        $site = $this->site('Future', 'https://future.example.com', now()->addDay());

        $this->artisan('sources:dispatch-checks', ['--site' => (string) $site->id])->assertSuccessful();

        Queue::assertPushed(fn (CheckMonitoredSiteJob $job): bool => $job->siteId === $site->id);
    }

    public function test_recent_pending_claim_prevents_duplicate_dispatch_but_stale_claim_is_recovered(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        Queue::fake();
        $pending = $this->site('Pending', 'https://pending.example.com', now()->subHour());
        $pending->forceFill(['check_pending_at' => now()->subHour()])->save();
        $stale = $this->site('Stale', 'https://stale.example.com', now()->subHour());
        $stale->forceFill(['check_pending_at' => now()->subHours(25)])->save();

        $this->artisan('sources:dispatch-checks')->assertSuccessful();

        Queue::assertPushed(CheckMonitoredSiteJob::class, 1);
        Queue::assertNotPushed(CheckMonitoredSiteJob::class, fn (CheckMonitoredSiteJob $job): bool => $job->siteId === $pending->id);
        Queue::assertPushed(CheckMonitoredSiteJob::class, fn (CheckMonitoredSiteJob $job): bool => $job->siteId === $stale->id && $job->claimToken !== null);
    }

    public function test_telegram_dispatch_selects_due_channels_and_reserves_10_minutes(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        config(['services.telegram.monitoring_enabled' => true]);
        Queue::fake();
        $due = $this->channel('due_channel', now()->subMinute());
        $future = $this->channel('future_channel', now()->addMinute());

        $this->artisan('telegram-channels:dispatch-checks')->assertSuccessful();

        Queue::assertPushed(CheckTelegramChannelJob::class, 1);
        Queue::assertPushed(fn (CheckTelegramChannelJob $job): bool => $job->channelId === $due->id);
        Queue::assertNotPushed(fn (CheckTelegramChannelJob $job): bool => $job->channelId === $future->id);
        $this->assertTrue($due->fresh()->next_check_at->equalTo(now()->subMinute()));
        $this->assertNotNull($due->fresh()->check_pending_at);
    }

    private function site(string $name, string $url, ?Carbon $nextCheckAt = null): MonitoredSite
    {
        return MonitoredSite::create([
            'name' => $name,
            'base_url' => $url,
            'source_type' => 'html',
            'enabled' => true,
            'next_check_at' => $nextCheckAt,
        ]);
    }

    private function channel(string $username, ?Carbon $nextCheckAt = null): TelegramChannel
    {
        return TelegramChannel::create([
            'title' => $username,
            'username' => $username,
            'url' => 'https://t.me/'.$username,
            'enabled' => true,
            'next_check_at' => $nextCheckAt,
        ]);
    }
}
