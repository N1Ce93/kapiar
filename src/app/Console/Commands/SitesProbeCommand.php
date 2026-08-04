<?php

namespace App\Console\Commands;

use App\Services\Monitoring\SiteProbeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sites:probe {url : Site URL to inspect}')]
#[Description('Detect whether a site can be monitored through RSS or HTML')]
class SitesProbeCommand extends Command
{
    public function handle(SiteProbeService $probeService): int
    {
        $probe = $probeService->probe((string) $this->argument('url'));

        $this->info('Site probe result');
        $this->table(['Field', 'Value'], [
            ['Base URL', $probe['base_url']],
            ['Recommended source', $probe['source_type'] ?: 'not detected'],
            ['RSS URL', $probe['feed_url'] ?: '-'],
            ['HTML listing URL', $probe['listing_url'] ?: '-'],
            ['HTML article links', (string) $probe['html_article_count']],
        ]);

        return $probe['source_type'] ? self::SUCCESS : self::FAILURE;
    }
}
