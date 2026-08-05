<?php

namespace Tests\Unit;

use App\Services\Monitoring\TelegramNotifier;
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
}
