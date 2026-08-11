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

        $this->assertTrue((new TelegramNotifier)->sendMessage('Test message'));

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

        $this->assertTrue((new TelegramNotifier)->sendMessage('Test message'));

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
            'posted_at' => Carbon::createFromTimestamp(1786370398, config('app.timezone')),
            'url' => 'https://t.me/oblasna_zp/924',
        ]);
        $message->setRelation('channel', new TelegramChannel(['username' => 'oblasna_zp']));

        $this->assertTrue((new TelegramNotifier)->sendTelegramChannelMention($message, ['keyword']));

        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Дата поста: 2026-08-10 16:59'));
    }

    public function test_it_includes_source_id_in_disabled_notifications(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1002354975882',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $notifier = new TelegramNotifier;

        $this->assertTrue($notifier->sendSourceDisabled('site', 42, 'Example News', 'Connection failed'));
        $this->assertTrue($notifier->sendSourceDisabled('telegram', 17, '@test_channel', 'Authorization failed'));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request['text'] === "Источник автоматически отключён\n\nТип: site\nID: 42\nИсточник: Example News\nПричина: Connection failed");
        Http::assertSent(fn ($request): bool => $request['text'] === "Источник автоматически отключён\n\nТип: telegram\nID: 17\nИсточник: @test_channel\nПричина: Authorization failed");
    }
}
