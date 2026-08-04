<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\SiteProbeService;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sites:add
    {url : Site URL to add}
    {--name= : Human-readable site name}
    {--source= : Force source type: rss or html}
    {--feed-url= : RSS feed URL when forcing or overriding RSS}
    {--listing-url= : HTML listing URL when forcing or overriding HTML}
    {--backfill-limit=500 : Number of old articles to save immediately}
    {--no-backfill : Add the site without collecting old articles}')]
#[Description('Add a monitored site, auto-detect RSS/HTML, and backfill old articles without Telegram')]
class SitesAddCommand extends Command
{
    public function handle(SiteProbeService $probeService, ArticleMonitorService $monitorService): int
    {
        $forcedSource = $this->option('source') ? strtolower((string) $this->option('source')) : null;

        if ($forcedSource !== null && ! in_array($forcedSource, ['rss', 'html'], true)) {
            $this->error('Invalid source. Use rss or html.');

            return self::FAILURE;
        }

        $probe = $probeService->probe((string) $this->argument('url'));
        $sourceType = $forcedSource ?: $probe['source_type'];
        $feedUrl = $this->option('feed-url') ?: $probe['feed_url'];
        $listingUrl = $this->option('listing-url') ?: $probe['listing_url'];

        if ($sourceType === 'rss' && ! $feedUrl) {
            $this->error('RSS source was selected, but no valid RSS feed was detected. Pass --feed-url=.');

            return self::FAILURE;
        }

        if ($sourceType === 'html' && ! $listingUrl) {
            $listingUrl = $probe['base_url'];
        }

        if (! $sourceType) {
            $this->error('Could not detect RSS or HTML articles for this site. Try --source=html --listing-url=https://example.com/news');

            return self::FAILURE;
        }

        $site = MonitoredSite::updateOrCreate(
            ['base_url' => $probe['base_url']],
            [
                'name' => $this->option('name') ?: $probe['name'],
                'source_type' => $sourceType,
                'feed_url' => $sourceType === 'rss' ? UrlHelper::cleanUrl((string) $feedUrl) : null,
                'listing_url' => $sourceType === 'html' ? UrlHelper::cleanUrl((string) $listingUrl) : null,
                'enabled' => true,
            ],
        );

        $this->info(($site->wasRecentlyCreated ? 'Site added: ' : 'Site updated: ').$site->name);
        $this->line('Source: '.$site->source_type);
        $this->line('Feed URL: '.($site->feed_url ?: '-'));
        $this->line('Listing URL: '.($site->listing_url ?: '-'));

        if ($this->option('no-backfill')) {
            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('backfill-limit'));
        $this->info('Backfilling old articles without Telegram...');
        $stats = $monitorService->ingestSite($site->fresh(), $limit, backfill: true, analyze: false, notify: false);

        $this->table(['Found', 'Saved', 'Already known'], [[
            $stats['found'],
            $stats['created'],
            $stats['skipped'],
        ]]);

        return self::SUCCESS;
    }
}
