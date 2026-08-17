<?php

namespace App\Console\Commands;

use App\Jobs\CheckMonitoredSiteJob;
use App\Models\MonitoredSite;
use App\Services\Monitoring\SourceHealthService;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('sources:dispatch-checks
    {--site= : Site id, name, host, or base URL}
    {--limit=20 : Maximum number of fresh articles per site job}
    {--sites-limit= : Maximum number of sites to dispatch in this run}
    {--no-notify : Save and analyze new articles without Telegram notifications}')]
#[Description('Dispatch monitored site checks to the sources queue')]
class SourcesDispatchChecksCommand extends Command
{
    public function handle(SourceHealthService $healthService): int
    {
        $sites = $this->sites();
        $limit = max(1, (int) $this->option('limit'));

        if ($sites->isEmpty()) {
            $this->warn('No sites found.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $bypassSchedule = $this->option('site') !== null;

        foreach ($sites as $site) {
            $claimToken = $healthService->reserveCheck($site, $bypassSchedule);

            if ($claimToken === null) {
                continue;
            }

            try {
                CheckMonitoredSiteJob::dispatch(
                    siteId: $site->id,
                    limit: $limit,
                    analyze: true,
                    notify: ! $this->option('no-notify'),
                    claimToken: $claimToken,
                );
            } catch (Throwable $exception) {
                $healthService->releaseCheck($site, $claimToken);

                throw $exception;
            }

            $dispatched++;
        }

        $this->info('Selected site check jobs: '.$dispatched);

        return self::SUCCESS;
    }

    private function sites()
    {
        $query = MonitoredSite::query()->where('enabled', true);
        $site = $this->option('site');

        if (! $site) {
            $query->where(function ($query): void {
                $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
            })->where(function ($query): void {
                $query->whereNull('check_pending_at')->orWhere('check_pending_at', '<=', now()->subHours(SourceHealthService::CHECK_CLAIM_TIMEOUT_HOURS));
            })->orderBy('next_check_at')->orderBy('last_queued_at')->orderBy('id');

            if ($this->option('sites-limit')) {
                $query->limit(max(1, (int) $this->option('sites-limit')));
            }

            return $query->get();
        }

        $site = (string) $site;

        if (is_numeric($site)) {
            return $query->where('id', (int) $site)->orderBy('id')->get();
        }

        $host = str_contains($site, '.') ? UrlHelper::host(UrlHelper::normalizeBaseUrl($site)) : $site;

        return $query->where(function ($query) use ($site, $host) {
            $query->orWhere('name', 'like', '%'.$site.'%')
                ->orWhere('base_url', 'like', '%'.$site.'%')
                ->orWhere('base_url', 'like', '%'.$host.'%');
        })->orderBy('id')->get();
    }
}
