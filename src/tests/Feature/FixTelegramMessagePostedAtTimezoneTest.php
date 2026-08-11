<?php

namespace Tests\Feature;

use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixTelegramMessagePostedAtTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_existing_utc_wall_clock_values_to_the_app_timezone(): void
    {
        config(['app.timezone' => 'Europe/Kyiv']);

        $channel = TelegramChannel::create([
            'title' => 'Test Channel',
            'username' => 'test_channel',
            'url' => 'https://t.me/test_channel',
            'telegram_peer' => '@test_channel',
            'enabled' => true,
        ]);
        $message = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 924,
            'text' => 'Test message',
            'posted_at' => '2026-08-10 13:59:58',
        ]);
        $winterMessage = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 925,
            'text' => 'Winter test message',
            'posted_at' => '2026-01-10 13:59:58',
        ]);

        $migration = require database_path('migrations/2026_08_11_070000_fix_telegram_message_posted_at_timezone.php');
        $migration->up();

        $this->assertSame('2026-08-10 16:59:58', $message->refresh()->posted_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-10 15:59:58', $winterMessage->refresh()->posted_at->format('Y-m-d H:i:s'));
    }
}
