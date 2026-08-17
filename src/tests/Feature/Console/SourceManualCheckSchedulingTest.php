<?php

namespace Tests\Feature\Console;

use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\TelegramChannelMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class SourceManualCheckSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_batch_site_check_processes_only_due_sources_and_releases_claim(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $due = $this->site('Due', 'https://due.example.com', now()->subMinute());
        $future = $this->site('Future', 'https://future.example.com', now()->addHour());
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldReceive('ingestSite')
            ->once()
            ->with(Mockery::on(fn (MonitoredSite $site): bool => $site->id === $due->id), 50, false, true, false)
            ->andReturn($this->stats());
        $this->app->instance(ArticleMonitorService::class, $monitor);

        $this->artisan('parser:check', ['--no-notify' => true])->assertSuccessful();

        $due->refresh();
        $this->assertNull($due->check_pending_at);
        $this->assertTrue($due->next_check_at->equalTo(now()->addMinutes(30)));
        $this->assertTrue($future->fresh()->next_check_at->equalTo(now()->addHour()));
    }

    public function test_explicit_site_check_bypasses_date_but_not_active_claim(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $future = $this->site('Future', 'https://future.example.com', now()->addHour());
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldReceive('ingestSite')->once()->andReturn($this->stats());
        $this->app->instance(ArticleMonitorService::class, $monitor);

        $this->artisan('parser:check', ['--site' => (string) $future->id, '--no-notify' => true])->assertSuccessful();
        $this->assertTrue($future->fresh()->next_check_at->equalTo(now()->addMinutes(30)));

        $future->forceFill(['check_pending_at' => now()])->save();
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldNotReceive('ingestSite');
        $this->app->instance(ArticleMonitorService::class, $monitor);

        $this->artisan('parser:check', ['--site' => (string) $future->id, '--no-notify' => true])->assertSuccessful();
        $this->assertNotNull($future->fresh()->check_pending_at);
    }

    public function test_explicit_telegram_check_bypasses_date_and_uses_10_minute_schedule(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $channel = TelegramChannel::create([
            'title' => 'Future Channel',
            'username' => 'future_channel',
            'url' => 'https://t.me/future_channel',
            'enabled' => true,
            'next_check_at' => now()->addHour(),
        ]);
        $monitor = Mockery::mock(TelegramChannelMonitorService::class);
        $monitor->shouldReceive('ingestChannel')->once()->andReturn($this->stats());
        $this->app->instance(TelegramChannelMonitorService::class, $monitor);

        $this->artisan('telegram-channels:check', ['--channel' => (string) $channel->id, '--no-notify' => true])->assertSuccessful();

        $channel->refresh();
        $this->assertNull($channel->check_pending_at);
        $this->assertTrue($channel->next_check_at->equalTo(now()->addMinutes(10)));
    }

    private function site(string $name, string $url, Carbon $nextCheckAt): MonitoredSite
    {
        return MonitoredSite::create([
            'name' => $name,
            'base_url' => $url,
            'source_type' => 'html',
            'enabled' => true,
            'next_check_at' => $nextCheckAt,
        ]);
    }

    private function stats(): array
    {
        return ['found' => 0, 'created' => 0, 'skipped' => 0, 'analyzed' => 0, 'hits' => 0, 'sent' => 0];
    }
}
