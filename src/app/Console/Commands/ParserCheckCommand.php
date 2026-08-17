<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\SourceHealthService;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('parser:check
    {--site= : Site id, name, host, or base URL}
    {--limit=50 : Maximum number of fresh articles per site}
    {--sites-limit= : Maximum number of sites to check in this run}
    {--no-notify : Save and analyze new articles without Telegram notifications}')]
#[Description('Check monitored sites for new articles and notify Telegram on keyword hits')]
class ParserCheckCommand extends Command
{
    public function handle(ArticleMonitorService $monitorService, SourceHealthService $healthService): int
    {
        $sites = $this->sites();
        $limit = max(1, (int) $this->option('limit'));

        if ($sites->isEmpty()) {
            $this->warn('No sites found.');

            return self::SUCCESS;
        }

        $bypassSchedule = $this->option('site') !== null;

        foreach ($sites as $site) {
            $claimToken = $healthService->reserveCheck($site, $bypassSchedule);

            if ($claimToken === null) {
                $this->warn('Site check is already pending or not due: '.$site->name);

                continue;
            }

            $this->info('Checking '.$site->name.'...');

            try {
                try {
                    $stats = $monitorService->ingestSite($site, $limit, backfill: false, analyze: true, notify: ! $this->option('no-notify'));
                } catch (Throwable $exception) {
                    $healthService->recordFailure($site, $exception, $claimToken);
                    $this->error($exception->getMessage());

                    continue;
                }

                $healthService->recordSuccess($site, $claimToken);

                $this->table(['Found', 'New', 'Already known', 'Analyzed', 'Hits', 'Telegram sent'], [[
                    $stats['found'],
                    $stats['created'],
                    $stats['skipped'],
                    $stats['analyzed'],
                    $stats['hits'],
                    $stats['sent'],
                ]]);
            } finally {
                $healthService->releaseCheck($site, $claimToken);
            }
        }

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
            })->orderBy('next_check_at')->orderBy('id');

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
