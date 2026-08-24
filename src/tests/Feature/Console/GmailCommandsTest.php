<?php

namespace Tests\Feature\Console;

use App\Jobs\CheckGmailJob;
use App\Models\EmailSubjectKeyword;
use App\Models\GmailMonitorState;
use App\Models\GmailProcessingMessage;
use App\Services\Monitoring\GmailCheckAlreadyRunningException;
use App\Services\Monitoring\GmailCheckRunner;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GmailCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_keyword_is_added_with_an_interactively_selected_label(): void
    {
        $this->artisan('email-keywords:add', ['phrase' => 'Оставить свой отзыв'])
            ->expectsQuestion('Название Gmail-ярлыка', 'Відгуки')
            ->assertSuccessful();

        $this->assertDatabaseHas('email_subject_keywords', [
            'phrase' => 'Оставить свой отзыв',
            'label_name' => 'Відгуки',
            'enabled' => true,
        ]);
    }

    public function test_system_gmail_labels_are_rejected(): void
    {
        $this->artisan('email-keywords:add', ['phrase' => 'Оставить свой отзыв'])
            ->expectsQuestion('Название Gmail-ярлыка', 'SPAM')
            ->assertFailed();

        $this->assertDatabaseCount('email_subject_keywords', 0);
    }

    public function test_updating_a_keyword_replaces_its_label_in_pending_operations(): void
    {
        $keyword = EmailSubjectKeyword::create([
            'phrase' => 'Оставить свой отзыв',
            'label_name' => 'Старый ярлык',
        ]);
        $otherKeyword = EmailSubjectKeyword::create([
            'phrase' => 'Новая жалоба',
            'label_name' => 'Старый ярлык',
        ]);
        $state = GmailMonitorState::create([
            'email_address' => 'monitor@gmail.com',
            'history_id' => '100',
            'initialized_at' => now(),
            'last_checked_at' => now(),
        ]);
        $processing = GmailProcessingMessage::create([
            'gmail_monitor_state_id' => $state->id,
            'gmail_message_id' => 'message-1',
            'matched_keywords' => [$keyword->phrase, $otherKeyword->phrase],
            'target_labels' => ['Старый ярлык'],
        ]);

        $this->artisan('email-keywords:add', ['phrase' => 'оставить свой отзыв'])
            ->expectsQuestion('Название Gmail-ярлыка', 'Відгуки')
            ->assertSuccessful();

        $this->assertSame(['Відгуки', 'Старый ярлык'], $processing->fresh()->target_labels);
    }

    public function test_dispatch_requires_monitoring_to_be_enabled(): void
    {
        Queue::fake();
        config(['services.gmail.monitoring_enabled' => false]);

        $this->artisan('gmail:dispatch-check')->assertSuccessful();
        Queue::assertNotPushed(CheckGmailJob::class);

        config(['services.gmail.monitoring_enabled' => true]);
        $this->artisan('gmail:dispatch-check')->assertSuccessful();
        Queue::assertPushed(CheckGmailJob::class, 1);
    }

    public function test_runtime_lock_rejects_a_second_check(): void
    {
        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(GmailCheckAlreadyRunningException::class);
            app(GmailCheckRunner::class)->run(static fn (Closure $heartbeat): null => null);
        } finally {
            $lock->release();
        }
    }

    public function test_runtime_lock_can_be_refreshed_during_a_long_check(): void
    {
        $result = app(GmailCheckRunner::class)->run(function (Closure $heartbeat): string {
            $heartbeat();
            $secondLock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
            $this->assertFalse($secondLock->get());

            return 'completed';
        });

        $this->assertSame('completed', $result);
    }
}
