<?php

namespace App\Jobs;

use App\Models\TelegramChannel;
use App\Services\Monitoring\SourceHealthService;
use App\Services\Monitoring\TelegramChannelMonitorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CheckTelegramChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const CIRCUIT_CACHE_KEY = 'telegram:monitoring:circuit-open';

    public const SESSION_LOCK_KEY = 'telegram:monitoring:session-lock';

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public ?string $claimToken = null;

    public function __construct(
        public readonly int $channelId,
        public readonly int $limit = 5,
        public readonly bool $analyze = true,
        public readonly bool $notify = true,
        ?string $claimToken = null,
    ) {
        $this->claimToken = $claimToken;
        $this->onQueue('telegram');
    }

    public function handle(TelegramChannelMonitorService $monitorService, SourceHealthService $healthService): void
    {
        $query = TelegramChannel::query()->whereKey($this->channelId)->where('enabled', true);

        if ($this->claimToken !== null) {
            $query->where('check_claim_token', $this->claimToken);
        } else {
            $query->whereNull('check_claim_token');
        }

        $channel = $query->first();

        if (! $channel) {
            if ($this->claimToken !== null) {
                TelegramChannel::query()
                    ->whereKey($this->channelId)
                    ->where('check_claim_token', $this->claimToken)
                    ->update(['check_pending_at' => null, 'check_claim_token' => null]);
            }

            return;
        }

        try {
            if (Cache::has(self::CIRCUIT_CACHE_KEY)) {
                return;
            }

            $lock = Cache::lock(self::SESSION_LOCK_KEY, $this->timeout + 60);

            if (! $lock->get()) {
                return;
            }

            try {
                try {
                    $monitorService->ingestChannel($channel, $this->limit, backfill: false, analyze: $this->analyze, notify: $this->notify);
                } catch (Throwable $exception) {
                    if ($healthService->isSystemicTelegramFailure($exception)) {
                        Cache::put(self::CIRCUIT_CACHE_KEY, $healthService->errorMessage($exception), now()->addMinutes(10));

                        return;
                    }

                    $healthService->recordFailure($channel, $exception, $this->claimToken);

                    return;
                }

                $healthService->recordSuccess($channel, $this->claimToken);
            } finally {
                $lock->release();
            }
        } finally {
            $healthService->releaseCheck($channel, $this->claimToken);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $query = TelegramChannel::query()->whereKey($this->channelId);

        if ($this->claimToken !== null) {
            $query->where('check_claim_token', $this->claimToken);
        } else {
            $query->whereNull('check_claim_token');
        }

        $channel = $query->first();

        if ($channel) {
            $healthService = app(SourceHealthService::class);

            try {
                Cache::put(self::CIRCUIT_CACHE_KEY, $healthService->errorMessage($exception), now()->addMinutes(10));
            } finally {
                $healthService->releaseCheck($channel, $this->claimToken);
            }
        }
    }
}
