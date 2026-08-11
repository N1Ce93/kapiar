<?php

namespace Tests\Feature\Console;

use App\Models\TelegramChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramChannelsEnableCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enables_channel_by_id_and_clears_disabled_state(): void
    {
        $channel = TelegramChannel::create([
            'title' => 'Test Channel',
            'username' => 'test_channel',
            'url' => 'https://t.me/test_channel',
            'telegram_peer' => '@test_channel',
            'enabled' => false,
            'consecutive_failures' => 4,
            'last_message_id' => 12345,
            'last_checked_at' => '2026-08-10 10:00:00',
            'last_backfilled_at' => '2026-08-09 10:00:00',
            'last_queued_at' => '2026-08-10 09:50:00',
            'last_success_at' => '2026-08-08 10:00:00',
            'last_error_at' => '2026-08-10 10:00:00',
            'last_error' => 'Connection failed',
            'disabled_at' => '2026-08-10 10:00:00',
            'disabled_reason' => 'auto-disabled after 4 consecutive failures',
        ]);

        $this->artisan('telegram-channels:enable', ['channel' => (string) $channel->id])
            ->expectsOutput("Telegram channel enabled: @test_channel (ID: {$channel->id})")
            ->assertSuccessful();

        $channel->refresh();

        $this->assertTrue($channel->enabled);
        $this->assertSame(0, $channel->consecutive_failures);
        $this->assertNull($channel->last_error_at);
        $this->assertNull($channel->last_error);
        $this->assertNull($channel->disabled_at);
        $this->assertNull($channel->disabled_reason);
        $this->assertSame(12345, $channel->last_message_id);
        $this->assertSame('@test_channel', $channel->telegram_peer);
        $this->assertSame('2026-08-10 10:00:00', $channel->last_checked_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-09 10:00:00', $channel->last_backfilled_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-10 09:50:00', $channel->last_queued_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-08 10:00:00', $channel->last_success_at?->format('Y-m-d H:i:s'));
    }

    public function test_it_enables_channel_by_telegram_url(): void
    {
        $channel = TelegramChannel::create([
            'title' => 'Test Channel',
            'username' => 'test_channel',
            'url' => 'https://t.me/test_channel',
            'enabled' => false,
        ]);

        $this->artisan('telegram-channels:enable', ['channel' => 'https://t.me/Test_Channel/'])
            ->assertSuccessful();

        $this->assertTrue($channel->fresh()->enabled);
    }

    public function test_it_does_not_clear_errors_when_channel_is_already_enabled(): void
    {
        $channel = TelegramChannel::create([
            'title' => 'Test Channel',
            'username' => 'test_channel',
            'url' => 'https://t.me/test_channel',
            'enabled' => true,
            'consecutive_failures' => 2,
            'last_error' => 'Temporary error',
        ]);

        $this->artisan('telegram-channels:enable', ['channel' => '@test_channel'])
            ->expectsOutput("Telegram channel is already enabled: @test_channel (ID: {$channel->id})")
            ->assertSuccessful();

        $channel->refresh();

        $this->assertSame(2, $channel->consecutive_failures);
        $this->assertSame('Temporary error', $channel->last_error);
    }

    public function test_it_fails_for_invalid_or_unknown_channel(): void
    {
        $this->artisan('telegram-channels:enable', ['channel' => 'https://t.me/test_channel/123'])
            ->expectsOutput('Telegram channel must be a public @username or https://t.me/username URL.')
            ->assertFailed();

        $this->artisan('telegram-channels:enable', ['channel' => '999'])
            ->expectsOutput('Telegram channel not found: 999')
            ->assertFailed();
    }
}
