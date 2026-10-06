<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

class GmailQuotaLimiter
{
    public function acquire(int $units): void
    {
        $key = $this->cacheKey();
        $budget = max(1, (int) config('services.gmail.quota_units_per_minute', 4000));
        $interval = (int) ceil($units * 60_000_000 / $budget);

        do {
            $wait = Cache::lock($key.':lock', 10)->block(5, function () use ($key, $interval): int {
                $now = $this->nowInMicroseconds();
                $wait = max(0, (int) Cache::get($key, 0) - $now);

                if ($wait === 0) {
                    Cache::put($key, $now + $interval, max(120, (int) ceil($interval / 1_000_000) + 60));
                }

                return $wait;
            });

            if ($wait > 0) {
                Sleep::usleep($wait);
            }
        } while ($wait > 0);
    }

    public function cooldown(int $seconds): void
    {
        $key = $this->cacheKey();

        Cache::lock($key.':lock', 10)->block(5, function () use ($key, $seconds): void {
            $now = $this->nowInMicroseconds();
            $next = max((int) Cache::get($key, 0), $now + $seconds * 1_000_000);
            Cache::put($key, $next, max(120, (int) ceil(($next - $now) / 1_000_000) + 60));
        });
    }

    private function nowInMicroseconds(): int
    {
        return (int) now()->format('Uu');
    }

    private function cacheKey(): string
    {
        // One monitored mailbox per application; token rotation must not reset its quota budget.
        return 'gmail:quota:next-request:'.hash('sha256', (string) config('services.gmail.client_id'));
    }
}
