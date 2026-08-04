<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('parser:check
    {--site= : Site id, name, host, or base URL}
    {--limit=50 : Maximum number of fresh articles per site}')]
#[Description('Check monitored sites for new articles and notify Telegram on keyword hits')]
class ParserCheckCommand extends Command
{
    public function handle(ArticleMonitorService $monitorService): int
    {
        $sites = $this->sites();
        $limit = max(1, (int) $this->option('limit'));

        if ($sites->isEmpty()) {
            $this->warn('No sites found.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $this->info('Checking '.$site->name.'...');
            $stats = $monitorService->ingestSite($site, $limit, backfill: false, analyze: true, notify: true);

            $this->table(['Found', 'New', 'Already known', 'Analyzed', 'Hits', 'Telegram sent'], [[
                $stats['found'],
                $stats['created'],
                $stats['skipped'],
                $stats['analyzed'],
                $stats['hits'],
                $stats['sent'],
            ]]);
        }

        return self::SUCCESS;
    }

    private function sites()
    {
        $query = MonitoredSite::query()->where('enabled', true)->orderBy('id');
        $site = $this->option('site');

        if (! $site) {
            return $query->get();
        }

        $site = (string) $site;

        if (is_numeric($site)) {
            return $query->where('id', (int) $site)->get();
        }

        $host = str_contains($site, '.') ? UrlHelper::host(UrlHelper::normalizeBaseUrl($site)) : $site;

        return $query->where(function ($query) use ($site, $host) {
            $query->orWhere('name', 'like', '%'.$site.'%')
                ->orWhere('base_url', 'like', '%'.$site.'%')
                ->orWhere('base_url', 'like', '%'.$host.'%');
        })->get();
    }
}
