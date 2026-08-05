<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('parser:backfill
    {--site= : Site id, name, host, or base URL}
    {--limit=500 : Maximum number of old articles per site}
    {--analyze : Analyze old articles for keyword hits without Telegram}')]
#[Description('Collect old articles so they are not sent as new Telegram notifications')]
class ParserBackfillCommand extends Command
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
            $this->info('Backfilling '.$site->name.'...');

            try {
                $stats = $monitorService->ingestSite($site, $limit, backfill: true, analyze: (bool) $this->option('analyze'), notify: false);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());
                continue;
            }

            $this->table(['Found', 'Saved', 'Already known', 'Analyzed', 'Hits'], [[
                $stats['found'],
                $stats['created'],
                $stats['skipped'],
                $stats['analyzed'],
                $stats['hits'],
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
