<?php

namespace App\Jobs;

use App\Services\Monitoring\GmailCheckAlreadyRunningException;
use App\Services\Monitoring\GmailCheckRunner;
use App\Services\Monitoring\GmailMonitorService;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckGmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

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
        } catch (GmailCheckAlreadyRunningException) {
            $this->release(60);
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return 'gmail-monitor';
    }
}
