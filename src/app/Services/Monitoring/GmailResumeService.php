<?php

namespace App\Services\Monitoring;

use Closure;
use RuntimeException;
use Throwable;

class GmailResumeService
{
    public function __construct(
        private readonly GmailCheckRunner $runner,
        private readonly GmailMonitorService $monitor,
        private readonly GmailMonitoringControl $control,
    ) {}

    public function run(?Closure $guard = null): array
    {
        if (! config('services.gmail.monitoring_enabled')) {
            throw new RuntimeException('Gmail monitoring is disabled. Set GMAIL_MONITORING_ENABLED=true before resuming it.');
        }

        return $this->runner->run(function (Closure $heartbeat) use ($guard): array {
            $guard?->__invoke();

            try {
                $stats = $this->monitor->check($heartbeat);
                $heartbeat();
                $guard?->__invoke();
                $this->control->resume();

                return $stats;
            } catch (Throwable $exception) {
                $this->control->pause($exception);

                throw $exception;
            }
        }, allowPaused: true);
    }
}
