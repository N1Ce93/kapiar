<?php

namespace Tests\Unit;

use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Services\Monitoring\TelegramNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    public function test_it_replies_to_configured_topic_anchor_for_target_supergroup(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1002354975882',
            'services.telegram.reply_to_chat_id' => '-1002354975882',
            'services.telegram.reply_to_message_id' => '8240',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->assertTrue((new TelegramNotifier())->sendMessage('Test message'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === '-1002354975882'
            && $request['text'] === 'Test message'
            && $request['disable_web_page_preview'] === false
            && $request['reply_to_message_id'] === 8240);
    }

    public function test_it_does_not_reply_to_topic_anchor_for_other_chats(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1000000000000',
            'services.telegram.reply_to_chat_id' => '-1002354975882',
            'services.telegram.reply_to_message_id' => '8240',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->assertTrue((new TelegramNotifier())->sendMessage('Test message'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === '-1000000000000'
            && ! isset($request['reply_to_message_id']));
    }

    public function test_it_formats_telegram_post_date_in_app_timezone(): void
    {
        config([
            'app.timezone' => 'Europe/Kyiv',
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1002354975882',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $message = new TelegramMessage([
            'posted_at' => Carbon::parse('2026-08-07 05:00:00', 'UTC'),
            'url' => 'https://t.me/test_channel/1',
        ]);
        $message->setRelation('channel', new TelegramChannel(['username' => 'test_channel']));

        $this->assertTrue((new TelegramNotifier())->sendTelegramChannelMention($message, ['keyword']));

        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Дата поста: 2026-08-07 08:00'));
    }
}
