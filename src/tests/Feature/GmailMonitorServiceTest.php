<?php

namespace Tests\Feature;

use App\Models\EmailSubjectKeyword;
use App\Models\GmailMonitorState;
use App\Models\GmailProcessingMessage;
use App\Services\Monitoring\GmailApiException;
use App\Services\Monitoring\GmailMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailMonitorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Kyiv',
            'services.gmail.client_id' => 'gmail-client-id',
            'services.gmail.client_secret' => 'gmail-client-secret',
            'services.gmail.refresh_token' => 'gmail-refresh-token',
            'services.telegram.bot_token' => 'telegram-token',
            'services.telegram.chat_id' => '-100123',
            'services.telegram.review_thread_id' => '9123',
            'services.telegram.reply_to_chat_id' => null,
            'services.telegram.reply_to_message_id' => null,
        ]);
        Cache::flush();
        Carbon::setTestNow('2026-08-24 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_check_only_saves_a_checkpoint_and_does_not_process_existing_mail(): void
    {
        Http::fake(fn (Request $request) => $this->responseFor($request, [
            'profile' => ['emailAddress' => 'monitor@gmail.com', 'historyId' => '100'],
        ]));

        $stats = app(GmailMonitorService::class)->check();

        $this->assertTrue($stats['initialized']);
        $this->assertDatabaseHas('gmail_monitor_states', [
            'email_address' => 'monitor@gmail.com',
            'history_id' => '100',
        ]);
        $this->assertDatabaseCount('gmail_processing_messages', 0);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/history'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_it_processes_an_unread_inbox_review_applies_all_keyword_labels_and_keeps_no_history(): void
    {
        $state = $this->state();
        EmailSubjectKeyword::create(['phrase' => 'Оставить свой отзыв', 'label_name' => 'Відгуки']);
        EmailSubjectKeyword::create(['phrase' => 'Новая жалоба', 'label_name' => 'Скарги']);

        Http::fake(fn (Request $request) => $this->responseFor($request, [
            'profile' => ['emailAddress' => 'monitor@gmail.com', 'historyId' => '101'],
            'history' => [
                'historyId' => '101',
                'history' => [['messagesAdded' => [['message' => ['id' => 'message-1']]]]],
            ],
            'message' => $this->gmailMessage('message-1', ['INBOX', 'UNREAD']),
            'labels' => ['labels' => [['id' => 'Label_1', 'name' => 'Відгуки', 'type' => 'user']]],
            'created_label' => ['id' => 'Label_2', 'name' => 'Скарги'],
        ]));

        $stats = app(GmailMonitorService::class)->check();

        $this->assertFalse($stats['initialized']);
        $this->assertSame(1, $stats['found']);
        $this->assertSame(1, $stats['matched']);
        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(0, $stats['pending']);
        $this->assertSame('101', $state->fresh()->history_id);
        $this->assertDatabaseCount('gmail_processing_messages', 0);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org')
            && $request['parse_mode'] === 'HTML'
            && $request['disable_web_page_preview'] === true
            && $request['message_thread_id'] === 9123
            && str_contains((string) $request['text'], '<b>Новый отзыв</b>')
            && str_contains((string) $request['text'], "<b>Отправитель:</b> Иван Иванов\n<b>Email:</b> sender@example.com")
            && str_contains((string) $request['text'], '<blockquote expandable>Очень хороший врач &lt;спасибо&gt;</blockquote>')
            && str_contains((string) $request['text'], 'https://mail.google.com/mail/u/0/?tab=rm&amp;ogbl#all/thread-message-1')
            && ! str_contains((string) $request['text'], 'monitor@gmail.com')
            && ! str_contains((string) $request['text'], 'Відгуки'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/messages/message-1?')
            && str_contains($request->url(), 'format=full'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages/message-1/modify')
            && $request['addLabelIds'] === ['Label_1', 'Label_2']
            && $request['removeLabelIds'] === ['UNREAD']);
    }

    public function test_it_skips_read_archived_spam_sent_draft_and_trash_messages_but_advances_the_checkpoint(): void
    {
        $state = $this->state();
        EmailSubjectKeyword::create(['phrase' => 'Оставить свой отзыв', 'label_name' => 'Відгуки']);
        $messages = [
            'read-message' => $this->gmailMessage('read-message', ['INBOX']),
            'archived-message' => $this->gmailMessage('archived-message', ['UNREAD']),
            'spam-message' => $this->gmailMessage('spam-message', ['INBOX', 'UNREAD', 'SPAM']),
            'sent-message' => $this->gmailMessage('sent-message', ['INBOX', 'UNREAD', 'SENT']),
            'draft-message' => $this->gmailMessage('draft-message', ['INBOX', 'UNREAD', 'DRAFT']),
            'trash-message' => $this->gmailMessage('trash-message', ['INBOX', 'UNREAD', 'TRASH']),
        ];

        Http::fake(fn (Request $request) => $this->responseFor($request, [
            'profile' => ['emailAddress' => 'monitor@gmail.com', 'historyId' => '104'],
            'history' => [
                'historyId' => '104',
                'history' => [['messagesAdded' => array_map(
                    static fn (string $id): array => ['message' => ['id' => $id]],
                    array_keys($messages),
                )]],
            ],
            'messages' => $messages,
        ]));

        $stats = app(GmailMonitorService::class)->check();

        $this->assertSame(6, $stats['found']);
        $this->assertSame(0, $stats['matched']);
        $this->assertSame('104', $state->fresh()->history_id);
        $this->assertDatabaseCount('gmail_processing_messages', 0);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_it_retries_only_gmail_changes_when_telegram_was_already_sent(): void
    {
        $state = $this->state();
        GmailProcessingMessage::create([
            'gmail_monitor_state_id' => $state->id,
            'gmail_message_id' => 'message-1',
            'matched_keywords' => ['Оставить свой отзыв'],
            'target_labels' => ['Відгуки'],
            'telegram_sent_at' => now()->subMinute(),
        ]);

        Http::fake(fn (Request $request) => $this->responseFor($request, [
            'profile' => ['emailAddress' => 'monitor@gmail.com', 'historyId' => '101'],
            'history' => ['historyId' => '101'],
            'message' => $this->gmailMessage('message-1', ['INBOX', 'UNREAD']),
            'labels' => ['labels' => [['id' => 'Label_1', 'name' => 'Відгуки', 'type' => 'user']]],
        ]));

        $stats = app(GmailMonitorService::class)->check();

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['completed']);
        $this->assertDatabaseCount('gmail_processing_messages', 0);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/messages/message-1/modify'));
    }

    public function test_an_invalid_system_label_does_not_block_other_pending_messages(): void
    {
        $state = $this->state();
        GmailProcessingMessage::create([
            'gmail_monitor_state_id' => $state->id,
            'gmail_message_id' => 'message-invalid',
            'matched_keywords' => ['Неверный ключ'],
            'target_labels' => ['SPAM'],
        ]);
        GmailProcessingMessage::create([
            'gmail_monitor_state_id' => $state->id,
            'gmail_message_id' => 'message-valid',
            'matched_keywords' => ['Оставить свой отзыв'],
            'target_labels' => ['Відгуки'],
        ]);

        Http::fake(fn (Request $request) => $this->responseFor($request, [
            'profile' => ['emailAddress' => 'monitor@gmail.com', 'historyId' => '101'],
            'history' => ['historyId' => '101'],
            'messages' => [
                'message-invalid' => $this->gmailMessage('message-invalid', ['INBOX', 'UNREAD']),
                'message-valid' => $this->gmailMessage('message-valid', ['INBOX', 'UNREAD']),
            ],
            'labels' => ['labels' => [
                ['id' => 'SPAM', 'name' => 'SPAM', 'type' => 'system'],
                ['id' => 'Label_1', 'name' => 'Відгуки', 'type' => 'user'],
            ]],
        ]));

        $stats = app(GmailMonitorService::class)->check();

        $this->assertSame(1, $stats['completed']);
        $this->assertSame(1, $stats['pending']);
        $this->assertDatabaseHas('gmail_processing_messages', ['gmail_message_id' => 'message-invalid']);
        $this->assertDatabaseMissing('gmail_processing_messages', ['gmail_message_id' => 'message-valid']);
    }

    public function test_gmail_modify_is_retried_without_sending_telegram_again(): void
    {
        $this->state();
        EmailSubjectKeyword::create(['phrase' => 'Оставить свой отзыв', 'label_name' => 'Відгуки']);
        $historyCalls = 0;
        $modifyCalls = 0;
        $telegramCalls = 0;

        Http::fake(function (Request $request) use (&$historyCalls, &$modifyCalls, &$telegramCalls) {
            $fixtures = [
                'profile' => ['emailAddress' => 'monitor@gmail.com', 'historyId' => '101'],
                'history' => $historyCalls === 0
                    ? ['historyId' => '101', 'history' => [['messagesAdded' => [['message' => ['id' => 'message-1']]]]]]
                    : ['historyId' => '101'],
                'message' => $this->gmailMessage('message-1', ['INBOX', 'UNREAD']),
                'labels' => ['labels' => [['id' => 'Label_1', 'name' => 'Відгуки', 'type' => 'user']]],
                'modify_status' => $modifyCalls === 0 ? 500 : 200,
            ];

            if (str_contains($request->url(), '/history')) {
                $historyCalls++;
            }

            if (str_contains($request->url(), '/modify')) {
                $modifyCalls++;
            }

            if (str_contains($request->url(), 'api.telegram.org')) {
                $telegramCalls++;
            }

            return $this->responseFor($request, $fixtures);
        });

        try {
            app(GmailMonitorService::class)->check();
            $this->fail('Expected Gmail modify to fail.');
        } catch (GmailApiException $exception) {
            $this->assertSame(500, $exception->status);
        }

        $this->assertNotNull(GmailProcessingMessage::first()?->telegram_sent_at);
        $this->assertSame(1, $telegramCalls);

        $stats = app(GmailMonitorService::class)->check();

        $this->assertSame(1, $telegramCalls);
        $this->assertSame(1, $stats['completed']);
        $this->assertDatabaseCount('gmail_processing_messages', 0);
    }

    public function test_it_recovers_an_expired_history_checkpoint_using_unread_inbox_mail_without_spam(): void
    {
        $state = $this->state();
        $state->forceFill([
            'initialized_at' => now()->subMinute(),
            'last_checked_at' => now()->subSeconds(30),
        ])->save();

        Http::fake(fn (Request $request) => $this->responseFor($request, [
            'profile' => ['emailAddress' => 'monitor@gmail.com', 'historyId' => '200'],
            'history_status' => 404,
            'message_list' => ['messages' => []],
        ]));

        $stats = app(GmailMonitorService::class)->check();

        $this->assertTrue($stats['recovered']);
        $this->assertSame('200', $state->fresh()->history_id);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/messages?')
            && str_contains(urldecode($request->url()), 'q=in:inbox is:unread after:'.$state->initialized_at->getTimestamp())
            && str_contains($request->url(), 'includeSpamTrash=false'));
    }

    public function test_it_rejects_a_different_oauth_account_after_initialization(): void
    {
        $this->state();
        Http::fake(fn (Request $request) => $this->responseFor($request, [
            'profile' => ['emailAddress' => 'other@gmail.com', 'historyId' => '200'],
        ]));

        $this->expectExceptionMessage('Gmail OAuth account changed');

        app(GmailMonitorService::class)->check();
    }

    private function state(): GmailMonitorState
    {
        return GmailMonitorState::create([
            'email_address' => 'monitor@gmail.com',
            'history_id' => '100',
            'initialized_at' => now()->subHour(),
            'last_checked_at' => now()->subMinute(),
        ]);
    }

    /** @return array<string,mixed> */
    private function gmailMessage(string $id, array $labels): array
    {
        $body = "Вступление\nТекст сообщения:\nОчень хороший врач <спасибо>\n--\nПодпись";

        return [
            'id' => $id,
            'threadId' => 'thread-'.$id,
            'labelIds' => $labels,
            'internalDate' => '1787562000000',
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [
                    ['name' => 'From', 'value' => '=?UTF-8?B?0JjQstCw0L0g0JjQstCw0L3QvtCy?= <sender@example.com>'],
                    ['name' => 'Subject', 'value' => 'ЗОКБ: ОСТАВИТЬ СВОЙ ОТЗЫВ / новая жалоба'],
                ],
                'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')],
            ],
        ];
    }

    /** @param array<string,mixed> $fixtures */
    private function responseFor(Request $request, array $fixtures)
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($url === 'https://oauth2.googleapis.com/token') {
            return Http::response(['access_token' => 'gmail-access-token', 'expires_in' => 3600]);
        }

        if (str_ends_with($path, '/profile')) {
            return Http::response($fixtures['profile']);
        }

        if (str_ends_with($path, '/history')) {
            return Http::response($fixtures['history'] ?? [], $fixtures['history_status'] ?? 200);
        }

        if (str_ends_with($path, '/messages')) {
            return Http::response($fixtures['message_list'] ?? []);
        }

        if ($request->method() === 'GET' && preg_match('~/messages/([^/]+)$~', $path, $matches)) {
            return Http::response($fixtures['messages'][$matches[1]] ?? $fixtures['message'] ?? []);
        }

        if ($request->method() === 'GET' && str_ends_with($path, '/labels')) {
            return Http::response($fixtures['labels'] ?? ['labels' => []]);
        }

        if ($request->method() === 'POST' && str_ends_with($path, '/labels')) {
            return Http::response($fixtures['created_label'] ?? ['id' => 'Label_created']);
        }

        if (str_ends_with($path, '/modify')) {
            return Http::response(['id' => 'message-1'], $fixtures['modify_status'] ?? 200);
        }

        if (str_contains($url, 'api.telegram.org')) {
            return Http::response($fixtures['telegram_response'] ?? ['ok' => true], $fixtures['telegram_status'] ?? 200);
        }

        return Http::response(['unhandled' => $url], 500);
    }
}
