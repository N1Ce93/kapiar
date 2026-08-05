<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleDiscoveryService;
use App\Services\Monitoring\SiteProbeService;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

#[Signature('sites:audit
    {--site= : Site id, name, host, or base URL}
    {--only-empty : Audit only enabled sites without saved articles}
    {--limit=5 : Maximum discovered article URLs to test per site}')]
#[Description('Audit monitored sites without saving articles or sending notifications')]
class SitesAuditCommand extends Command
{
    public function handle(SiteProbeService $probeService, ArticleDiscoveryService $discoveryService): int
    {
        $sites = $this->sites();

        if ($sites->isEmpty()) {
            $this->warn('No sites found.');

            return self::SUCCESS;
        }

        $rows = [];
        $limit = max(1, (int) $this->option('limit'));

        foreach ($sites as $site) {
            $status = $this->httpStatus($site->source_type === 'rss' ? ($site->feed_url ?: $site->base_url) : ($site->listing_url ?: $site->base_url));
            $probe = $probeService->probe($site->base_url);

            try {
                $items = $discoveryService->discover($site, $limit);
                $error = null;
            } catch (Throwable $exception) {
                $items = [];
                $error = $exception->getMessage();
            }

            $rows[] = [
                $site->id,
                $site->name,
                $site->source_type,
                $status,
                count($items),
                $probe['source_type'] ?: '-',
                $probe['feed_url'] ?: '-',
                $probe['listing_url'] ?: '-',
                $probe['html_article_count'],
                $error ?: '-',
            ];
        }

        $this->table([
            'ID',
            'Site',
            'Configured',
            'HTTP',
            'Found',
            'Detected',
            'Detected RSS',
            'Detected HTML',
            'HTML links',
            'Error',
        ], $rows);

        return self::SUCCESS;
    }

    private function sites()
    {
        $query = MonitoredSite::query()->where('enabled', true)->orderBy('id');
        $site = $this->option('site');

        if ($this->option('only-empty')) {
            $query->doesntHave('articles');
        }

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

    private function httpStatus(string $url): string
    {
        try {
            $response = Http::withHeaders(UrlHelper::crawlerHeaders())
                ->withoutVerifying()
                ->timeout(15)
                ->retry(1, 500)
                ->get($url);
        } catch (Throwable $exception) {
            return 'error: '.$exception->getMessage();
        }

        return (string) $response->status();
    }
}
