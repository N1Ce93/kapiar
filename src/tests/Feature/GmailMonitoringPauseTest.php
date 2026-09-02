<?php

namespace Tests\Feature;

use App\Jobs\CheckGmailJob;
use App\Models\GmailMonitorControl;
use App\Services\Monitoring\GmailCheckRunner;
use App\Services\Monitoring\GmailMonitoringControl;
use App\Services\Monitoring\GmailMonitoringPausedException;
use App\Services\Monitoring\GmailMonitorService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GmailMonitoringPauseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gmail.monitoring_enabled' => true,
            'services.telegram.bot_token' => 'telegram-token',
            'services.telegram.chat_id' => '-100123',
        ]);
        Cache::flush();
    }

    public function test_a_failed_check_pauses_monitoring_and_alerts_only_once(): void
    {
        $this->fakeSuccessfulTelegram();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andThrow(new RuntimeException('OAuth token refresh failed.'));
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->artisan('gmail:check')
            ->expectsOutput('OAuth token refresh failed.')
            ->assertFailed();

        $control = GmailMonitorControl::query()->findOrFail(GmailMonitorControl::SINGLETON_ID);
        $this->assertNotNull($control->paused_at);
        $this->assertSame('OAuth token refresh failed.', $control->last_error);
        $this->assertNotNull($control->alert_attempted_at);
        $this->assertNotNull($control->alert_delivered_at);
        Http::assertSentCount(1);

        $this->assertFalse(app(GmailMonitoringControl::class)->pause(new RuntimeException('Another failure.')));
        Http::assertSentCount(1);

        try {
            app(GmailCheckRunner::class)->run(static fn (): string => 'not called');
            $this->fail('Expected paused monitoring to reject the check.');
        } catch (GmailMonitoringPausedException) {
            $this->assertTrue(true);
        }

        Http::assertSentCount(1);
    }

    public function test_the_job_has_one_attempt_and_its_failure_hook_is_idempotent(): void
    {
        $this->fakeSuccessfulTelegram();
        $job = new CheckGmailJob;

        $this->assertSame(1, $job->tries);
        $this->assertFalse(method_exists($job, 'backoff'));

        $job->failed(new RuntimeException('Worker timed out.'));
        $job->failed(new RuntimeException('Worker timed out again.'));

        $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
        $this->assertSame('Worker timed out.', GmailMonitorControl::query()->firstOrFail()->last_error);
        Http::assertSentCount(1);
    }

    public function test_a_job_exception_reaches_the_queue_failure_lifecycle(): void
    {
        $this->fakeSuccessfulTelegram();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andThrow(new RuntimeException('Gmail failed.'));

        $job = new CheckGmailJob;

        try {
            $job->handle(app(GmailCheckRunner::class), $monitor);
            $this->fail('Expected the job exception to escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Gmail failed.', $exception->getMessage());
            $this->assertNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
            $job->failed($exception);
        }

        $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
        Http::assertSentCount(1);
    }

    public function test_the_failure_hook_releases_an_orphaned_runtime_lock(): void
    {
        $this->fakeSuccessfulTelegram();
        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        (new CheckGmailJob)->failed(new TimeoutExceededException('Worker timed out.'));

        $replacement = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($replacement->get());
        $replacement->release();
    }

    public function test_timeout_lock_is_released_even_when_pause_persistence_fails(): void
    {
        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($lock->get());
        $control = Mockery::mock(GmailMonitoringControl::class);
        $control->shouldReceive('pause')->once()->andThrow(new RuntimeException('Database is unavailable.'));
        $this->app->instance(GmailMonitoringControl::class, $control);

        try {
            (new CheckGmailJob)->failed(new TimeoutExceededException('Worker timed out.'));
            $this->fail('Expected pause persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Database is unavailable.', $exception->getMessage());
        }

        $replacement = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($replacement->get());
        $replacement->release();
    }

    public function test_resume_clears_the_pause_and_dispatches_an_immediate_check(): void
    {
        $this->fakeSuccessfulTelegram();
        app(GmailMonitoringControl::class)->pause(new RuntimeException('Expired token.'));
        Queue::fake();

        $this->artisan('gmail:resume')
            ->expectsOutput('Gmail monitoring resumed and an immediate check was dispatched.')
            ->assertSuccessful();

        $control = GmailMonitorControl::query()->firstOrFail();
        $this->assertNull($control->paused_at);
        $this->assertNotNull($control->incident_id);
        $this->assertSame('Expired token.', $control->last_error);
        Queue::assertPushed(CheckGmailJob::class, 1);
    }

    public function test_resume_restores_the_pause_when_dispatch_fails(): void
    {
        $this->fakeSuccessfulTelegram();
        app(GmailMonitoringControl::class)->pause(new RuntimeException('Expired token.'));
        $realDispatcher = app(Dispatcher::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis is unavailable.'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->artisan('gmail:resume')
            ->expectsOutput('Gmail monitoring remains paused because the immediate check could not be dispatched.')
            ->assertFailed();

        $control = GmailMonitorControl::query()->firstOrFail();
        $this->assertNotNull($control->paused_at);
        $this->assertNotNull($control->incident_id);
        $this->assertSame('Expired token.', $control->last_error);
        Http::assertSentCount(1);

        $uniqueLock = new UniqueLock(app(CacheRepository::class));
        $this->assertTrue($uniqueLock->acquire(new CheckGmailJob));
        $uniqueLock->release(new CheckGmailJob);

        $this->app->instance(Dispatcher::class, $realDispatcher);
        Queue::fake();
        $this->artisan('gmail:resume')->assertSuccessful();
        Queue::assertPushed(CheckGmailJob::class, 1);
    }

    public function test_dispatch_is_skipped_while_monitoring_is_paused(): void
    {
        $this->fakeSuccessfulTelegram();
        app(GmailMonitoringControl::class)->pause(new RuntimeException('Expired token.'));
        Queue::fake();

        $this->artisan('gmail:dispatch-check')
            ->expectsOutput('Gmail monitoring is paused. Run gmail:resume after fixing the error.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_an_old_resume_failure_cannot_restore_a_newer_incident(): void
    {
        $this->fakeSuccessfulTelegram();
        $control = app(GmailMonitoringControl::class);
        $control->pause(new RuntimeException('First incident.'));
        $firstIncident = $control->state();
        $this->assertTrue($control->resume());
        $this->assertTrue($control->pause(new RuntimeException('Second incident.')));
        $this->assertTrue($control->resume());

        $this->assertFalse($control->restorePause($firstIncident));
        $current = $control->state();
        $this->assertNull($current->paused_at);
        $this->assertNotSame($firstIncident->incident_id, $current->incident_id);
    }

    public function test_a_rejected_alert_is_not_attempted_again_for_the_same_incident(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false])]);
        $control = app(GmailMonitoringControl::class);

        $this->assertTrue($control->pause(new RuntimeException('Expired token.')));
        $this->assertFalse($control->pause(new RuntimeException('Expired token again.')));

        $state = GmailMonitorControl::query()->firstOrFail();
        $this->assertNotNull($state->alert_attempted_at);
        $this->assertNull($state->alert_delivered_at);
        Http::assertSentCount(1);
    }

    public function test_status_reports_a_pause_without_calling_gmail(): void
    {
        $this->fakeSuccessfulTelegram();
        app(GmailMonitoringControl::class)->pause(new RuntimeException('Expired token.'));

        $this->artisan('gmail:status')
            ->expectsOutput('Gmail monitoring is paused.')
            ->expectsOutput('Reason: Expired token.')
            ->expectsOutput('Telegram alert delivered: yes')
            ->assertFailed();

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'googleapis.com'));
    }

    public function test_redis_visibility_timeout_exceeds_the_gmail_job_timeout(): void
    {
        $this->assertGreaterThan((new CheckGmailJob)->timeout, config('queue.connections.redis.retry_after'));
    }

    private function fakeSuccessfulTelegram(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }
}
