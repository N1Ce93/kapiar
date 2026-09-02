<?php

namespace App\Jobs;

use App\Services\Monitoring\GmailCheckAlreadyRunningException;
use App\Services\Monitoring\GmailCheckRunner;
use App\Services\Monitoring\GmailMonitoringControl;
use App\Services\Monitoring\GmailMonitoringPausedException;
use App\Services\Monitoring\GmailMonitorService;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CheckGmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = GmailCheckRunner::LOCK_SECONDS;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onQueue('email');
    }

    public function handle(GmailCheckRunner $runner, GmailMonitorService $monitor): void
    {
        try {
            $runner->run(fn (Closure $heartbeat): array => $monitor->check($heartbeat));
        } catch (GmailCheckAlreadyRunningException|GmailMonitoringPausedException) {
            return;
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            app(GmailMonitoringControl::class)->pause($exception);
        } finally {
            if ($exception instanceof TimeoutExceededException) {
                Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS)->forceRelease();
            }
        }
    }

    public function uniqueId(): string
    {
        return 'gmail-monitor';
    }
}
