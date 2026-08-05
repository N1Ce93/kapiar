<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\SiteProbeService;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sites:refresh-sources
    {--site= : Site id, name, host, or base URL}
    {--after-id= : Only probe sites with id greater than this value}
    {--limit= : Maximum number of sites to probe}
    {--dry-run : Show detected changes without saving them}')]
#[Description('Re-detect monitored site RSS/HTML sources and update source settings')]
class SitesRefreshSourcesCommand extends Command
{
    public function handle(SiteProbeService $probeService): int
    {
        $sites = $this->sites();

        if ($sites->isEmpty()) {
            $this->warn('No sites found.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $this->info('Probing '.$site->name.'...');
            $probe = $probeService->probe($site->base_url);

            $updates = $this->updatesFor($site, $probe);

            $this->table(['Field', 'Current', 'Detected'], [
                ['Source', $site->source_type, $updates['source_type'] ?? $site->source_type],
                ['RSS URL', $site->feed_url ?: '-', $updates['feed_url'] ?? ($site->feed_url ?: '-')],
                ['HTML listing URL', $site->listing_url ?: '-', $updates['listing_url'] ?? ($site->listing_url ?: '-')],
                ['HTML article links', '-', (string) $probe['html_article_count']],
            ]);

            if ($updates === []) {
                $this->line('No changes.');

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line('Dry run: not saved.');

                continue;
            }

            $site->forceFill($updates)->save();
            $this->line('Saved.');
        }

        return self::SUCCESS;
    }

    private function sites()
    {
        $query = MonitoredSite::query()->where('enabled', true)->orderBy('id');
        $site = $this->option('site');

        if ($site) {
            $site = (string) $site;

            if (is_numeric($site)) {
                $query->where('id', (int) $site);
            } else {
                $host = str_contains($site, '.') ? UrlHelper::host(UrlHelper::normalizeBaseUrl($site)) : $site;

                $query->where(function ($query) use ($site, $host) {
                    $query->orWhere('name', 'like', '%'.$site.'%')
                        ->orWhere('base_url', 'like', '%'.$site.'%')
                        ->orWhere('base_url', 'like', '%'.$host.'%');
                });
            }
        }

        if ($this->option('after-id')) {
            $query->where('id', '>', max(0, (int) $this->option('after-id')));
        }

        if ($this->option('limit')) {
            $query->limit(max(1, (int) $this->option('limit')));
        }

        return $query->get();
    }

    private function updatesFor(MonitoredSite $site, array $probe): array
    {
        $detectedSource = $probe['source_type'];

        if (! $detectedSource) {
            return [];
        }

        $updates = [];

        if ($detectedSource === 'rss' && $probe['feed_url']) {
            $updates = [
                'source_type' => 'rss',
                'feed_url' => UrlHelper::cleanUrl($probe['feed_url']),
                'listing_url' => null,
            ];
        } elseif ($detectedSource === 'html') {
            $currentListingUrl = $site->listing_url ? UrlHelper::cleanUrl($site->listing_url) : null;
            $baseUrl = UrlHelper::cleanUrl($site->base_url);
            $listingUrl = $currentListingUrl && $currentListingUrl !== $baseUrl
                ? $currentListingUrl
                : UrlHelper::cleanUrl($probe['listing_url'] ?: $site->base_url);

            $updates = [
                'source_type' => 'html',
                'feed_url' => null,
                'listing_url' => $listingUrl,
            ];
        }

        return array_filter(
            $updates,
            fn (mixed $value, string $key): bool => $site->{$key} !== $value,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
