<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CheckMonitoredSiteJob;
use App\Jobs\CheckTelegramChannelJob;
use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\TelegramChannelMonitorService;
use App\Services\Monitoring\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SourceAutoDisableNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_job_sends_disabled_site_id(): void
    {
        $site = MonitoredSite::create([
            'name' => 'Example News',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'enabled' => true,
            'consecutive_failures' => 3,
        ]);
        $monitor = Mockery::mock(ArticleMonitorService::class);
        $monitor->shouldReceive('ingestSite')->once()->andThrow(new RuntimeException('Site unavailable'));
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendSourceDisabled')
            ->once()
            ->with('site', $site->id, 'Example News', Mockery::on(
                fn (string $message): bool => str_contains($message, 'Site unavailable'),
            ))
            ->andReturnTrue();

        (new CheckMonitoredSiteJob($site->id))->handle($monitor, $notifier);

        $this->assertFalse($site->fresh()->enabled);
    }

    public function test_telegram_job_sends_disabled_channel_id(): void
    {
        Cache::forget(CheckTelegramChannelJob::CIRCUIT_CACHE_KEY);

        $channel = TelegramChannel::create([
            'title' => 'Test Channel',
            'username' => 'test_channel',
            'url' => 'https://t.me/test_channel',
            'enabled' => true,
            'consecutive_failures' => 3,
        ]);
        $monitor = Mockery::mock(TelegramChannelMonitorService::class);
        $monitor->shouldReceive('ingestChannel')->once()->andThrow(new RuntimeException('Channel unavailable'));
        $notifier = Mockery::mock(TelegramNotifier::class);
        $notifier->shouldReceive('sendSourceDisabled')
            ->once()
            ->with('telegram', $channel->id, '@test_channel', Mockery::on(
                fn (string $message): bool => str_contains($message, 'Channel unavailable'),
            ))
            ->andReturnTrue();

        (new CheckTelegramChannelJob($channel->id))->handle($monitor, $notifier);

        $this->assertFalse($channel->fresh()->enabled);
    }
}
