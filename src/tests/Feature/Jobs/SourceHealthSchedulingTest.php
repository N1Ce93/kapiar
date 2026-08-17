<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CheckMonitoredSiteJob;
use App\Jobs\CheckTelegramChannelJob;
use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\SourceHealthService;
use App\Services\Monitoring\TelegramChannelMonitorService;
use App\Services\Monitoring\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SourceHealthSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(CheckTelegramChannelJob::CIRCUIT_CACHE_KEY);

        parent::tearDown();
    }

    public function test_third_site_failure_pauses_checking_for_24_hours(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $site = $this->site(['consecutive_failures' => 2]);
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldReceive('ingestSite')->once()->andThrow(new RuntimeException('Site unavailable'));
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendSourcePaused')
            ->once()
            ->with('site', $site->id, 'Example News', Mockery::on(
                fn (Carbon $date): bool => $date->equalTo(now()->addHours(24)),
            ), Mockery::on(fn (string $message): bool => str_contains($message, 'Site unavailable')))
            ->andReturnTrue();

        (new CheckMonitoredSiteJob($site->id))->handle($monitor, new SourceHealthService($notifier));

        $site->refresh();
        $this->assertTrue($site->enabled);
        $this->assertSame(3, $site->consecutive_failures);
        $this->assertSame('temporary', $site->last_error_type);
        $this->assertNotNull($site->paused_at);
        $this->assertTrue($site->next_check_at->equalTo(now()->addHours(24)));
    }

    public function test_first_two_failures_use_15_minute_and_one_hour_backoff(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $site = $this->site();
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldNotReceive('sendSourcePaused');
        $healthService = new SourceHealthService($notifier);

        $healthService->recordFailure($site, new RuntimeException('Connection failed'));
        $site->refresh();
        $this->assertSame(1, $site->consecutive_failures);
        $this->assertTrue($site->next_check_at->equalTo(now()->addMinutes(15)));

        Carbon::setTestNow('2026-08-17 12:15:00');
        $healthService->recordFailure($site, new RuntimeException('Connection failed'));
        $site->refresh();
        $this->assertSame(2, $site->consecutive_failures);
        $this->assertTrue($site->next_check_at->equalTo(now()->addHour()));
    }

    public function test_permanent_site_error_is_disabled_only_after_24_hour_confirmation(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $site = $this->site([
            'consecutive_failures' => 3,
            'last_error_at' => now()->subHours(24),
            'last_error' => 'RSS feed returned HTTP 404',
            'last_error_type' => 'permanent',
        ]);
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldReceive('ingestSite')->once()->andThrow(new RuntimeException('RSS feed returned HTTP 404: https://example.com/feed'));
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendSourceDisabled')
            ->once()
            ->with('site', $site->id, 'Example News', Mockery::type('string'))
            ->andReturnTrue();

        (new CheckMonitoredSiteJob($site->id))->handle($monitor, new SourceHealthService($notifier));

        $site->refresh();
        $this->assertFalse($site->enabled);
        $this->assertNull($site->next_check_at);
        $this->assertNotNull($site->disabled_at);
    }

    public function test_temporary_error_keeps_source_enabled_after_daily_retry(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $site = $this->site([
            'consecutive_failures' => 8,
            'last_error_at' => now()->subHours(24),
            'last_error_type' => 'temporary',
            'paused_at' => now()->subHours(24),
        ]);
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldReceive('ingestSite')->once()->andThrow(new RuntimeException('RSS feed returned HTTP 503'));
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldNotReceive('sendSourceDisabled');
        $notifier->shouldNotReceive('sendSourcePaused');

        (new CheckMonitoredSiteJob($site->id))->handle($monitor, new SourceHealthService($notifier));

        $site->refresh();
        $this->assertTrue($site->enabled);
        $this->assertSame('temporary', $site->last_error_type);
        $this->assertTrue($site->next_check_at->equalTo(now()->addHours(24)));
    }

    public function test_success_after_pause_restores_normal_site_schedule_and_notifies(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $site = $this->site([
            'consecutive_failures' => 3,
            'last_error' => 'Temporary failure',
            'paused_at' => now()->subHour(),
        ]);
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldReceive('ingestSite')->once()->andReturn([]);
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendSourceRecovered')->once()->with('site', $site->id, 'Example News')->andReturnTrue();

        (new CheckMonitoredSiteJob($site->id))->handle($monitor, new SourceHealthService($notifier));

        $site->refresh();
        $this->assertSame(0, $site->consecutive_failures);
        $this->assertNull($site->last_error);
        $this->assertNull($site->paused_at);
        $this->assertTrue($site->next_check_at->equalTo(now()->addMinutes(30)));
    }

    public function test_queued_job_updates_only_its_claim_and_releases_it_after_success(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $site = $this->site();
        $notifier = Mockery::mock(TelegramNotifier::class);
        $healthService = new SourceHealthService($notifier);
        $claimToken = $healthService->reserveCheck($site);
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldReceive('ingestSite')->once()->andReturn([]);

        (new CheckMonitoredSiteJob($site->id, claimToken: $claimToken))->handle($monitor, $healthService);

        $site->refresh();
        $this->assertNull($site->check_pending_at);
        $this->assertTrue($site->next_check_at->equalTo(now()->addMinutes(30)));
    }

    public function test_consecutive_claims_in_the_same_second_have_unique_tokens(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $site = $this->site();
        $healthService = new SourceHealthService(Mockery::mock(TelegramNotifier::class));

        $firstToken = $healthService->reserveCheck($site);
        $healthService->releaseCheck($site, $firstToken);
        $secondToken = $healthService->reserveCheck($site);

        $this->assertNotNull($firstToken);
        $this->assertNotNull($secondToken);
        $this->assertNotSame($firstToken, $secondToken);
    }

    public function test_stale_job_cannot_run_under_a_newer_claim(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $site = $this->site(['check_pending_at' => now(), 'check_claim_token' => 'new-claim']);
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldNotReceive('ingestSite');
        $notifier = Mockery::mock(TelegramNotifier::class);

        (new CheckMonitoredSiteJob($site->id, claimToken: 'old-claim'))
            ->handle($monitor, new SourceHealthService($notifier));

        $this->assertTrue($site->fresh()->check_pending_at->equalTo(now()));
    }

    public function test_third_telegram_failure_pauses_channel_for_24_hours(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $channel = $this->channel(['consecutive_failures' => 2]);
        $monitor = Mockery::mock(TelegramChannelMonitorService::class);
        $monitor->shouldReceive('ingestChannel')->once()->andThrow(new RuntimeException('Channel unavailable'));
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendSourcePaused')
            ->once()
            ->with('telegram', $channel->id, '@test_channel', Mockery::type(Carbon::class), Mockery::type('string'))
            ->andReturnTrue();

        (new CheckTelegramChannelJob($channel->id))->handle($monitor, new SourceHealthService($notifier));

        $channel->refresh();
        $this->assertTrue($channel->enabled);
        $this->assertSame(3, $channel->consecutive_failures);
        $this->assertNotNull($channel->paused_at);
        $this->assertTrue($channel->next_check_at->equalTo(now()->addHours(24)));
    }

    public function test_systemic_telegram_failure_opens_circuit_without_changing_channel(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $channel = $this->channel();
        $monitor = Mockery::mock(TelegramChannelMonitorService::class);
        $monitor->shouldReceive('ingestChannel')->once()->andThrow(new RuntimeException('AUTH_KEY_UNREGISTERED'));
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldNotReceive('sendSourcePaused');
        $notifier->shouldNotReceive('sendSourceDisabled');

        (new CheckTelegramChannelJob($channel->id))->handle($monitor, new SourceHealthService($notifier));

        $channel->refresh();
        $this->assertSame(0, $channel->consecutive_failures);
        $this->assertNull($channel->last_error_at);
        $this->assertTrue(Cache::has(CheckTelegramChannelJob::CIRCUIT_CACHE_KEY));
    }

    public function test_permanent_telegram_error_is_disabled_after_24_hour_confirmation(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $channel = $this->channel([
            'consecutive_failures' => 3,
            'last_error_at' => now()->subHours(24),
            'last_error' => 'USERNAME_NOT_OCCUPIED',
            'last_error_type' => 'permanent',
            'paused_at' => now()->subHours(24),
        ]);
        $monitor = Mockery::mock(TelegramChannelMonitorService::class);
        $monitor->shouldReceive('ingestChannel')->once()->andThrow(new RuntimeException('USERNAME_NOT_OCCUPIED'));
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendSourceDisabled')
            ->once()
            ->with('telegram', $channel->id, '@test_channel', Mockery::type('string'))
            ->andReturnTrue();

        (new CheckTelegramChannelJob($channel->id))->handle($monitor, new SourceHealthService($notifier));

        $channel->refresh();
        $this->assertFalse($channel->enabled);
        $this->assertNull($channel->paused_at);
        $this->assertNull($channel->next_check_at);
    }

    public function test_successful_telegram_check_uses_10_minute_interval(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $channel = $this->channel();
        $monitor = Mockery::mock(TelegramChannelMonitorService::class);
        $monitor->shouldReceive('ingestChannel')->once()->andReturn([]);
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldNotReceive('sendSourceRecovered');

        (new CheckTelegramChannelJob($channel->id))->handle($monitor, new SourceHealthService($notifier));

        $this->assertTrue($channel->fresh()->next_check_at->equalTo(now()->addMinutes(10)));
    }

    private function site(array $attributes = []): MonitoredSite
    {
        return MonitoredSite::create($attributes + [
            'name' => 'Example News',
            'base_url' => 'https://example.com/',
            'source_type' => 'rss',
            'feed_url' => 'https://example.com/feed',
            'enabled' => true,
        ]);
    }

    private function channel(array $attributes = []): TelegramChannel
    {
        return TelegramChannel::create($attributes + [
            'title' => 'Test Channel',
            'username' => 'test_channel',
            'url' => 'https://t.me/test_channel',
            'enabled' => true,
        ]);
    }
}
