<?php

namespace App\Console\Commands;

use App\Jobs\CheckMonitoredSiteJob;
use App\Models\MonitoredSite;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sources:dispatch-checks
    {--site= : Site id, name, host, or base URL}
    {--limit=20 : Maximum number of fresh articles per site job}
    {--sites-limit= : Maximum number of sites to dispatch in this run}
    {--no-notify : Save and analyze new articles without Telegram notifications}')]
#[Description('Dispatch monitored site checks to the sources queue')]
class SourcesDispatchChecksCommand extends Command
{
    public function handle(): int
    {
        $sites = $this->sites();
        $limit = max(1, (int) $this->option('limit'));

        if ($sites->isEmpty()) {
            $this->warn('No sites found.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $site->forceFill(['last_queued_at' => now()])->save();

            CheckMonitoredSiteJob::dispatch(
                siteId: $site->id,
                limit: $limit,
                analyze: true,
                notify: ! $this->option('no-notify'),
            );
        }

        $this->info('Selected site check jobs: '.$sites->count());

        return self::SUCCESS;
    }

    private function sites()
    {
        $query = MonitoredSite::query()->where('enabled', true);
        $site = $this->option('site');

        if (! $site) {
            $query->orderBy('last_queued_at')->orderBy('last_checked_at')->orderBy('id');

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
