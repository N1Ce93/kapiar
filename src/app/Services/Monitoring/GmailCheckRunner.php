<?php

namespace App\Services\Monitoring;

use Closure;
use Illuminate\Support\Facades\Cache;

class GmailCheckRunner
{
    public const LOCK_KEY = 'gmail:monitoring:check-lock';

    public const LOCK_SECONDS = 1200;

    public function run(Closure $callback): mixed
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new GmailCheckAlreadyRunningException('A Gmail check is already running.');
        }

        try {
            return $callback(function () use ($lock): void {
                if (! $lock->refresh(self::LOCK_SECONDS)) {
                    throw new GmailCheckAlreadyRunningException('The Gmail check lock was lost.');
                }
            });
        } finally {
            $lock->release();
        }
    }
}
