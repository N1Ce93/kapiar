<?php

namespace App\Services\Monitoring;

use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class GmailCheckRunner
{
    public const LOCK_KEY = 'gmail:monitoring:check-lock';

    public const LOCK_SECONDS = 1200;

    public function __construct(
        private readonly GmailMonitoringControl $control,
    ) {}

    public function run(Closure $callback): mixed
    {
        if ($this->control->isPaused()) {
            throw new GmailMonitoringPausedException('Gmail monitoring is paused. Run gmail:resume after fixing the error.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new GmailCheckAlreadyRunningException('A Gmail check is already running.');
        }

        try {
            if ($this->control->isPaused()) {
                throw new GmailMonitoringPausedException('Gmail monitoring is paused. Run gmail:resume after fixing the error.');
            }

            return $callback(function () use ($lock): void {
                if (! $lock->refresh(self::LOCK_SECONDS)) {
                    throw new RuntimeException('The Gmail check lock was lost.');
                }
            });
        } finally {
            $lock->release();
        }
    }
}
