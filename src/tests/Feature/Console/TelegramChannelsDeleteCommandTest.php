<?php

namespace Tests\Feature\Console;

use App\Models\Keyword;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageKeywordHit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramChannelsDeleteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_a_channel_and_related_monitoring_data(): void
    {
        $channel = $this->channel();
        $message = TelegramMessage::create([
            'telegram_channel_id' => $channel->id,
            'message_id' => 123,
            'text' => 'Example message',
            'url' => 'https://t.me/test_channel/123',
        ]);
        $keyword = Keyword::create(['phrase' => 'example', 'enabled' => true]);
        $hit = TelegramMessageKeywordHit::create([
            'telegram_message_id' => $message->id,
            'keyword_id' => $keyword->id,
            'matched_text' => 'example',
        ]);

        $this->artisan('telegram-channels:delete', ['id' => (string) $channel->id, '--force' => true])
            ->expectsOutput("Telegram channel: @test_channel (ID: {$channel->id})")
            ->expectsOutput('Messages to delete: 1')
            ->expectsOutput("Telegram channel deleted: @test_channel (ID: {$channel->id}); messages deleted: 1")
            ->assertSuccessful();

        $this->assertDatabaseMissing('telegram_channels', ['id' => $channel->id]);
        $this->assertDatabaseMissing('telegram_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('telegram_message_keyword_hits', ['id' => $hit->id]);
        $this->assertDatabaseHas('keywords', ['id' => $keyword->id]);
    }

    public function test_it_keeps_the_channel_when_deletion_is_not_confirmed(): void
    {
        $channel = $this->channel();

        $this->artisan('telegram-channels:delete', ['id' => (string) $channel->id])
            ->expectsConfirmation('Delete this Telegram channel and all related messages?', 'no')
            ->expectsOutput('Deletion cancelled.')
            ->assertSuccessful();

        $this->assertDatabaseHas('telegram_channels', ['id' => $channel->id]);
    }

    public function test_it_rejects_invalid_or_unknown_ids(): void
    {
        $this->artisan('telegram-channels:delete', ['id' => 'invalid'])
            ->expectsOutput('Telegram channel ID must be a positive integer.')
            ->assertFailed();

        $this->artisan('telegram-channels:delete', ['id' => '999'])
            ->expectsOutput('Telegram channel not found: 999')
            ->assertFailed();
    }

    private function channel(): TelegramChannel
    {
        return TelegramChannel::create([
            'title' => 'Test Channel',
            'username' => 'test_channel',
            'url' => 'https://t.me/test_channel',
            'telegram_peer' => '@test_channel',
            'enabled' => true,
        ]);
    }
}
