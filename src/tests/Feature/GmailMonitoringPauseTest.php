<?php

namespace Tests\Feature;

use App\Jobs\CheckGmailJob;
use App\Models\GmailMonitorControl;
use App\Services\Monitoring\GmailCheckRunner;
use App\Services\Monitoring\GmailMonitoringControl;
use App\Services\Monitoring\GmailMonitoringPausedException;
use App\Services\Monitoring\GmailMonitorService;
use Closure;
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
        $this->assertSame('Worker timed out again.', GmailMonitorControl::query()->firstOrFail()->last_error);
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

    public function test_resume_only_clears_the_pause_after_a_successful_synchronous_check(): void
    {
        $this->fakeSuccessfulTelegram();
        app(GmailMonitoringControl::class)->pause(new RuntimeException('Expired token.'));
        Queue::fake();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->with(Mockery::type(Closure::class))
            ->andReturnUsing(function (Closure $heartbeat): array {
                $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
                $this->assertSame('Expired token.', GmailMonitorControl::query()->firstOrFail()->last_error);
                $this->assertFalse(Cache::lock(GmailCheckRunner::LOCK_KEY)->get());
                $heartbeat();

                return $this->successfulStats();
            });
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->artisan('gmail:resume')
            ->expectsOutput('Gmail check completed successfully. Monitoring is active.')
            ->assertSuccessful();

        $control = GmailMonitorControl::query()->firstOrFail();
        $this->assertNull($control->paused_at);
        $this->assertNotNull($control->incident_id);
        $this->assertNull($control->last_error);
        $this->assertNull($control->last_error_at);
        Queue::assertNothingPushed();
        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_a_failed_resume_keeps_the_pause_and_displays_the_current_error(): void
    {
        $this->fakeSuccessfulTelegram();
        app(GmailMonitoringControl::class)->pause(new RuntimeException('Expired token.'));
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andThrow(new RuntimeException('Gmail access is still unavailable.'));
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->artisan('gmail:resume')
            ->expectsOutput('Gmail access is still unavailable.')
            ->expectsOutput('Gmail monitoring was not activated. Fix the error and run gmail:resume again.')
            ->assertFailed();

        $control = GmailMonitorControl::query()->firstOrFail();
        $this->assertNotNull($control->paused_at);
        $this->assertNotNull($control->incident_id);
        $this->assertSame('Gmail access is still unavailable.', $control->last_error);
        Http::assertSentCount(1);

        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_resume_runs_a_check_even_when_monitoring_is_already_active(): void
    {
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andReturn($this->successfulStats());
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->artisan('gmail:resume')
            ->expectsOutput('Gmail check completed successfully. Monitoring is active.')
            ->assertSuccessful();

        $this->assertNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
    }

    public function test_a_successful_scheduled_check_clears_the_previous_error_only_after_completion(): void
    {
        $this->fakeSuccessfulTelegram();
        $control = app(GmailMonitoringControl::class);
        $control->pause(new RuntimeException('Previous timeout.'));
        $control->resume();
        $previous = $control->state();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andReturnUsing(function (): array {
            $state = GmailMonitorControl::query()->firstOrFail();
            $this->assertSame('Previous timeout.', $state->last_error);
            $this->assertNotNull($state->last_error_at);

            return $this->successfulStats();
        });

        (new CheckGmailJob)->handle(app(GmailCheckRunner::class), $monitor);

        $state = $control->state();
        $this->assertNull($state->last_error);
        $this->assertNull($state->last_error_at);
        $this->assertSame($previous->incident_id, $state->incident_id);
        $this->assertTrue($previous->alert_delivered_at->equalTo($state->alert_delivered_at));
        Http::assertSentCount(1);
    }

    public function test_a_successful_cli_check_clears_an_old_error_without_an_incident_id(): void
    {
        GmailMonitorControl::query()->firstOrFail()->update([
            'last_error' => 'Legacy timeout.',
            'last_error_at' => now()->subDay(),
        ]);
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andReturn($this->successfulStats());
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->artisan('gmail:check')->assertSuccessful();

        $state = GmailMonitorControl::query()->firstOrFail();
        $this->assertNull($state->last_error);
        $this->assertNull($state->last_error_at);
    }

    public function test_a_failed_check_replaces_the_previous_error_and_successful_resume_clears_it(): void
    {
        $this->fakeSuccessfulTelegram();
        GmailMonitorControl::query()->firstOrFail()->update([
            'last_error' => 'Old error.',
            'last_error_at' => now()->subDay(),
        ]);
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andThrow(new RuntimeException('New timeout.'));
        $monitor->shouldReceive('check')->once()->andReturn($this->successfulStats());
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->artisan('gmail:check')->assertFailed();

        $state = GmailMonitorControl::query()->firstOrFail();
        $this->assertNotNull($state->paused_at);
        $this->assertSame('New timeout.', $state->last_error);
        $this->assertNotNull($state->last_error_at);
        $this->assertTrue($state->last_error_at->isToday());

        $this->artisan('gmail:resume')->assertSuccessful();

        $state = $state->fresh();
        $this->assertNull($state->paused_at);
        $this->assertNull($state->last_error);
        $this->assertNull($state->last_error_at);
    }

    public function test_a_repeated_failed_resume_updates_the_error_without_sending_another_alert(): void
    {
        $this->fakeSuccessfulTelegram();
        $control = app(GmailMonitoringControl::class);
        $control->pause(new RuntimeException('First error.'));
        $previous = $control->state();
        $this->travel(1)->minute();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andThrow(new RuntimeException('Latest error.'));
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->artisan('gmail:resume')->assertFailed();

        $state = $control->state();
        $this->assertSame('Latest error.', $state->last_error);
        $this->assertTrue($state->last_error_at->greaterThan($previous->last_error_at));
        $this->assertTrue($state->paused_at->equalTo($previous->paused_at));
        $this->assertSame($previous->incident_id, $state->incident_id);
        Http::assertSentCount(1);
    }

    public function test_a_successful_older_check_cannot_clear_a_newer_incident(): void
    {
        $this->fakeSuccessfulTelegram();
        $control = app(GmailMonitoringControl::class);
        $control->pause(new RuntimeException('First incident.'));
        $control->resume();

        app(GmailCheckRunner::class)->run(function () use ($control): void {
            $control->pause(new RuntimeException('Newer incident.'));
            $control->resume();
        });

        $this->assertSame('Newer incident.', $control->state()->last_error);
        $this->assertNotNull($control->state()->last_error_at);
    }

    public function test_a_check_that_loses_its_lock_does_not_clear_the_previous_error(): void
    {
        GmailMonitorControl::query()->firstOrFail()->update([
            'last_error' => 'Previous timeout.',
            'last_error_at' => now(),
        ]);

        try {
            app(GmailCheckRunner::class)->run(function (): void {
                Cache::lock(GmailCheckRunner::LOCK_KEY)->forceRelease();
            });
            $this->fail('Expected a lost runtime lock to reject completion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The Gmail check lock was lost.', $exception->getMessage());
        }

        $state = GmailMonitorControl::query()->firstOrFail();
        $this->assertSame('Previous timeout.', $state->last_error);
        $this->assertNotNull($state->last_error_at);
    }

    public function test_a_failed_resume_pauses_previously_active_monitoring(): void
    {
        $this->fakeSuccessfulTelegram();
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldReceive('check')->once()->andThrow(new RuntimeException('Telegram notification was not accepted.'));
        $this->app->instance(GmailMonitorService::class, $monitor);

        $this->artisan('gmail:resume')
            ->expectsOutput('Telegram notification was not accepted.')
            ->assertFailed();

        $state = GmailMonitorControl::query()->firstOrFail();
        $this->assertNotNull($state->paused_at);
        $this->assertSame('Telegram notification was not accepted.', $state->last_error);
    }

    public function test_resume_does_not_run_or_clear_a_pause_if_another_check_is_running(): void
    {
        $this->fakeSuccessfulTelegram();
        app(GmailMonitoringControl::class)->pause(new RuntimeException('Expired token.'));
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldNotReceive('check');
        $this->app->instance(GmailMonitorService::class, $monitor);
        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('gmail:resume')
                ->expectsOutput('A Gmail check is already running.')
                ->assertFailed();

            $this->assertNotNull(GmailMonitorControl::query()->firstOrFail()->paused_at);
            $this->assertFalse(Cache::lock(GmailCheckRunner::LOCK_KEY)->get());
        } finally {
            $lock->release();
        }
    }

    public function test_resume_requires_monitoring_to_be_enabled_in_configuration(): void
    {
        config(['services.gmail.monitoring_enabled' => false]);
        $monitor = Mockery::mock(GmailMonitorService::class);
        $monitor->shouldNotReceive('check');
        $this->app->instance(GmailMonitorService::class, $monitor);
        Http::preventStrayRequests();

        $this->artisan('gmail:resume')
            ->expectsOutput('Gmail monitoring is disabled. Set GMAIL_MONITORING_ENABLED=true before resuming it.')
            ->assertFailed();

        Http::assertNothingSent();
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

    private function successfulStats(): array
    {
        return ['initialized' => false, 'found' => 0, 'matched' => 0, 'sent' => 0, 'completed' => 0, 'pending' => 0, 'recovered' => false];
    }
}
