<?php

namespace App\Jobs;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\SourceHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CheckMonitoredSiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public ?string $claimToken = null;

    public function __construct(
        public readonly int $siteId,
        public readonly int $limit = 20,
        public readonly bool $analyze = true,
        public readonly bool $notify = true,
        ?string $claimToken = null,
    ) {
        $this->claimToken = $claimToken;
        $this->onQueue('sources');
    }

    public function handle(ArticleMonitorService $monitorService, SourceHealthService $healthService): void
    {
        $query = MonitoredSite::query()->whereKey($this->siteId)->where('enabled', true);

        if ($this->claimToken !== null) {
            $query->where('check_claim_token', $this->claimToken);
        } else {
            $query->whereNull('check_claim_token');
        }

        $site = $query->first();

        if (! $site) {
            if ($this->claimToken !== null) {
                MonitoredSite::query()
                    ->whereKey($this->siteId)
                    ->where('check_claim_token', $this->claimToken)
                    ->update(['check_pending_at' => null, 'check_claim_token' => null]);
            }

            return;
        }

        try {
            try {
                $monitorService->ingestSite($site, $this->limit, backfill: false, analyze: $this->analyze, notify: $this->notify);
            } catch (Throwable $exception) {
                $healthService->recordFailure($site, $exception, $this->claimToken);

                return;
            }

            $healthService->recordSuccess($site, $this->claimToken);
        } finally {
            $healthService->releaseCheck($site, $this->claimToken);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $query = MonitoredSite::query()->whereKey($this->siteId);

        if ($this->claimToken !== null) {
            $query->where('check_claim_token', $this->claimToken);
        } else {
            $query->whereNull('check_claim_token');
        }

        $site = $query->first();

        if ($site) {
            $healthService = app(SourceHealthService::class);

            try {
                $healthService->recordFailure($site, $exception, $this->claimToken);
            } finally {
                $healthService->releaseCheck($site, $this->claimToken);
            }
        }
    }
}
