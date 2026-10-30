<?php

namespace Tests\Feature;

use App\Jobs\ResumeGmailJob;
use App\Models\GmailMonitorControl;
use App\Models\GmailMonitorState;
use App\Models\GmailProcessingMessage;
use App\Services\Monitoring\GmailCheckRunner;
use App\Services\Monitoring\GmailMonitorService;
use App\Services\Monitoring\GmailRecoveryService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GmailMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gmail.monitoring_enabled' => true,
            'services.gmail.client_secret' => 'secret-not-for-web',
            'services.gmail.refresh_token' => 'refresh-not-for-web',
            'queue.default' => 'redis',
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_status_and_resume_require_site_access(): void
    {
        $this->getJson('/gmail/status')->assertRedirect('/access');
        $this->postJson('/gmail/resume')->assertRedirect('/access');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_modal_is_available_on_both_monitoring_pages(): void
    {
        foreach (['/sites', '/telegram'] as $url) {
            $this->withSession(['site_access_granted' => true])->get($url)
                ->assertOk()
                ->assertSee('data-gmail-open', false)
                ->assertSee('id="gmail-modal"', false)
                ->assertSee('data-status-url="/gmail/status"', false)
                ->assertSee('data-resume-url="/gmail/resume"', false)
                ->assertSee('Парсер Gmail');
        }
        Http::assertNothingSent();
    }

    public function test_status_uses_saved_state_without_calling_google_or_exposing_credentials(): void
    {
        $state = $this->state();
        $this->pause();
        GmailProcessingMessage::create([
            'gmail_monitor_state_id' => $state->id,
            'gmail_message_id' => 'pending-1',
            'matched_keywords' => ['review'],
            'target_labels' => ['Reviews'],
        ]);

        $response = $this->withSession(['site_access_granted' => true])->getJson('/gmail/status')
            ->assertOk()
            ->assertJsonPath('status', 'paused')
            ->assertJsonPath('can_resume', true)
            ->assertJsonPath('account', 'monitor@gmail.com')
            ->assertJsonPath('last_error', 'Expired token.')
            ->assertJsonPath('pending', 1)
            ->assertJsonPath('recovery.status', null)
            ->assertDontSee('secret-not-for-web')
            ->assertDontSee('refresh-not-for-web');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_status_distinguishes_uninitialized_active_and_disabled(): void
    {
        $this->withSession(['site_access_granted' => true])->getJson('/gmail/status')
            ->assertJsonPath('status', 'uninitialized')
            ->assertJsonPath('can_resume', true);

        $this->state();
        $this->getJson('/gmail/status')->assertJsonPath('status', 'active')->assertJsonPath('can_resume', false);

        $this->pause();
        config(['services.gmail.monitoring_enabled' => false]);
        $this->getJson('/gmail/status')->assertJsonPath('status', 'disabled')->assertJsonPath('can_resume', false);
        Http::assertNothingSent();
    }

    public function test_resume_only_queues_one_background_job_and_preserves_the_pause(): void
    {
        $this->state();
        $this->pause();

        $this->withSession(['site_access_granted' => true])->postJson('/gmail/resume')
            ->assertAccepted()
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('can_resume', false)
            ->assertJsonPath('recovery.status', 'queued');

        $control = GmailMonitorControl::query()->firstOrFail();
        $this->assertNotNull($control->paused_at);
        $this->assertNotNull($control->recovery_requested_at);
        Queue::assertPushed(ResumeGmailJob::class, fn (ResumeGmailJob $job): bool => $job->queue === 'email'
            && $job->recoveryId === $control->recovery_id);

        $this->postJson('/gmail/resume')->assertUnprocessable()->assertJsonValidationErrors('gmail');
        Queue::assertPushed(ResumeGmailJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_resume_rejects_disabled_active_and_synchronous_configuration(): void
    {
        $this->state();
        $this->pause();
        config(['services.gmail.monitoring_enabled' => false]);
        $this->withSession(['site_access_granted' => true])->postJson('/gmail/resume')
            ->assertUnprocessable()->assertJsonValidationErrors('gmail');

        config(['services.gmail.monitoring_enabled' => true, 'queue.default' => 'sync']);
        $this->postJson('/gmail/resume')->assertUnprocessable()->assertJsonValidationErrors('gmail');

        config(['queue.default' => 'redis']);
        GmailMonitorControl::query()->firstOrFail()->update(['paused_at' => null]);
        $this->postJson('/gmail/resume')->assertUnprocessable()->assertJsonValidationErrors('gmail');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_an_existing_cli_or_scheduled_check_blocks_web_recovery(): void
    {
        $this->state();
        $this->pause();
        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        try {
            $this->withSession(['site_access_granted' => true])->getJson('/gmail/status')
                ->assertJsonPath('status', 'running')->assertJsonPath('can_resume', false);
            $this->postJson('/gmail/resume')->assertUnprocessable()->assertJsonValidationErrors('gmail');
            $this->assertTrue($lock->isOwnedByCurrentProcess());
        } finally {
            $lock->release();
        }

        Queue::assertNothingPushed();
        $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
    }

    public function test_job_uses_the_shared_resume_flow_and_records_delivery_counters(): void
    {
        $this->state();
        $this->pause();
        $job = $this->requestJob();
        $stats = $this->stats();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andReturnUsing(function (Closure $heartbeat) use ($stats): array {
            $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
            $this->assertSame('running', GmailMonitorControl::query()->firstOrFail()->recovery_status);
            $this->getJson('/gmail/status')->assertJsonPath('status', 'running')->assertJsonPath('can_resume', false);
            $heartbeat();

            return $stats;
        });
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->app->call([$job, 'handle']);

        $this->getJson('/gmail/status')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('recovery.status', 'succeeded')
            ->assertJsonPath('recovery.stats.sent', 2)
            ->assertJsonPath('recovery.stats.completed', 2);
        $control = GmailMonitorControl::query()->firstOrFail();
        $this->assertNull($control->paused_at);
        $this->assertSame($stats, $control->recovery_stats);
        $this->assertNotNull($control->recovery_finished_at);
        $this->assertFalse(Cache::lock(GmailCheckRunner::LOCK_KEY)->isLocked());

        // A duplicate delivery and a late failure must not run or overwrite a completed request.
        $this->app->call([$job, 'handle']);
        $job->failed(new RuntimeException('Late failure.'));
        $this->assertSame('succeeded', $control->fresh()->recovery_status);
        Http::assertNothingSent();
    }

    public function test_failed_job_preserves_pause_and_exposes_the_latest_attempt_error(): void
    {
        $this->state();
        $this->pause();
        $job = $this->requestJob();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andThrow(new RuntimeException('Google rejected access.'));
        $this->app->instance(GmailMonitorService::class, $monitor);

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Expected Gmail recovery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Google rejected access.', $exception->getMessage());
            $job->failed($exception);
        }

        $this->getJson('/gmail/status')->assertJsonPath('status', 'paused')
            ->assertJsonPath('can_resume', true)
            ->assertJsonPath('recovery.status', 'failed')
            ->assertJsonPath('recovery.error', 'Google rejected access.');
        $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
        Http::assertNothingSent();
    }

    public function test_queue_failure_is_reported_and_allows_retry_without_clearing_pause(): void
    {
        $this->state();
        $this->pause();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable.'));

        $this->withSession(['site_access_granted' => true])->postJson('/gmail/resume')
            ->assertUnprocessable()->assertJsonValidationErrors('gmail');

        $this->getJson('/gmail/status')->assertJsonPath('status', 'paused')
            ->assertJsonPath('can_resume', true)
            ->assertJsonPath('recovery.status', 'failed');
        $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
    }

    public function test_stale_queued_request_expires_and_old_jobs_cannot_overwrite_a_new_request(): void
    {
        $this->state();
        $this->pause();
        $oldJob = $this->requestJob();
        $this->travel(GmailRecoveryService::STALE_SECONDS + 1)->seconds();

        $this->getJson('/gmail/status')->assertJsonPath('status', 'paused')
            ->assertJsonPath('can_resume', true)
            ->assertJsonPath('recovery.status', 'failed');
        $this->postJson('/gmail/resume')->assertAccepted();
        $newId = GmailMonitorControl::query()->firstOrFail()->recovery_id;
        $this->assertNotSame($oldJob->recoveryId, $newId);
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldNotReceive('check');
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->app->call([$oldJob, 'handle']);
        $oldJob->failed(new RuntimeException('Old worker failure.'));

        $this->getJson('/gmail/status')->assertJsonPath('recovery.id', $newId)
            ->assertJsonPath('recovery.status', 'queued');
        $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
    }

    public function test_stale_running_request_waits_for_runtime_lock_before_expiring(): void
    {
        $this->state();
        $this->pause();
        $job = $this->requestJob();
        $this->assertTrue(app(GmailRecoveryService::class)->start($job->recoveryId));
        $this->travel(GmailRecoveryService::STALE_SECONDS + 1)->seconds();
        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        try {
            $this->getJson('/gmail/status')->assertJsonPath('status', 'running')
                ->assertJsonPath('recovery.status', 'running');
        } finally {
            $lock->release();
        }

        $this->getJson('/gmail/status')->assertJsonPath('status', 'paused')
            ->assertJsonPath('recovery.status', 'failed')->assertJsonPath('can_resume', true);
    }

    public function test_worker_failure_does_not_release_another_checks_lock(): void
    {
        $this->state();
        $this->pause();
        $job = $this->requestJob();
        $this->assertTrue(app(GmailRecoveryService::class)->start($job->recoveryId));
        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        try {
            $job->failed(new RuntimeException('Worker timed out.'));
            $this->assertTrue($lock->isOwnedByCurrentProcess());
            $this->assertSame('failed', GmailMonitorControl::query()->firstOrFail()->recovery_status);
            $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
        } finally {
            $lock->release();
        }
    }

    public function test_replaced_request_cannot_start_parsing_or_clear_the_pause(): void
    {
        $this->state();
        $this->pause();
        $job = $this->requestJob();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldNotReceive('check');
        $this->app->instance(GmailMonitorService::class, $monitor);
        $runner = Mockery::mock(GmailCheckRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function (Closure $callback, bool $allowPaused): array {
            $this->assertTrue($allowPaused);
            GmailMonitorControl::query()->firstOrFail()->update([
                'recovery_id' => 'replacement-request', 'recovery_status' => 'queued',
            ]);

            return $callback(static fn () => null);
        });
        $this->app->instance(GmailCheckRunner::class, $runner);

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Expected a replaced recovery request to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('більше не актуальний', $exception->getMessage());
        }

        $control = GmailMonitorControl::query()->firstOrFail();
        $this->assertNotNull($control->paused_at);
        $this->assertSame('replacement-request', $control->recovery_id);
        $this->assertSame('queued', $control->recovery_status);
        Http::assertNothingSent();
    }

    public function test_web_recovery_has_a_single_attempt_and_a_safe_visibility_timeout(): void
    {
        $job = new ResumeGmailJob('test-request');
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan($job->timeout, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThan($job->timeout, GmailRecoveryService::STALE_SECONDS);
    }

    public function test_initialization_can_be_queued_but_disabled_workers_cannot_resume(): void
    {
        $job = $this->requestJob();
        config(['services.gmail.monitoring_enabled' => false]);
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldNotReceive('check');
        $this->app->instance(GmailMonitorService::class, $monitor);

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('Expected disabled monitoring to reject recovery.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Gmail monitoring is disabled.', $exception->getMessage());
        }

        $this->getJson('/gmail/status')->assertJsonPath('status', 'disabled')->assertJsonPath('recovery.status', 'failed');
        Http::assertNothingSent();
    }

    private function state(): GmailMonitorState
    {
        return GmailMonitorState::create([
            'email_address' => 'monitor@gmail.com',
            'history_id' => '100',
            'initialized_at' => now()->subHour(),
            'last_checked_at' => now()->subMinutes(10),
        ]);
    }

    private function pause(): void
    {
        GmailMonitorControl::query()->firstOrFail()->update([
            'paused_at' => now(), 'last_error_at' => now(), 'last_error' => 'Expired token.',
        ]);
    }

    private function requestJob(): ResumeGmailJob
    {
        $this->withSession(['site_access_granted' => true])->postJson('/gmail/resume')->assertAccepted();

        return Queue::pushed(ResumeGmailJob::class)->last();
    }

    private function stats(): array
    {
        return ['initialized' => false, 'found' => 3, 'matched' => 2, 'sent' => 2, 'completed' => 2, 'pending' => 0, 'recovered' => false];
    }
}
