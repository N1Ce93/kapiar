<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\SiteProbeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sites:set-article-url-pattern
    {id : Site ID}
    {pattern? : Regex matched against the article URL path}
    {--clear : Remove the configured pattern}')]
#[Description('Set or clear the URL pattern used to identify articles for a site')]
class SitesSetArticleUrlPatternCommand extends Command
{
    public function handle(SiteProbeService $probeService): int
    {
        $identifier = trim((string) $this->argument('id'));
        $pattern = trim((string) ($this->argument('pattern') ?? ''));
        $clear = (bool) $this->option('clear');

        if (! ctype_digit($identifier) || (int) $identifier < 1) {
            $this->error('Site ID must be a positive integer.');

            return self::FAILURE;
        }

        if ($clear && $pattern !== '') {
            $this->error('Do not pass a pattern together with --clear.');

            return self::FAILURE;
        }

        if (! $clear && $pattern === '') {
            $this->error('Pass an article URL pattern or use --clear.');

            return self::FAILURE;
        }

        if (! $clear && ! $probeService->isValidArticleUrlPattern($pattern)) {
            $this->error('Invalid article URL pattern. Pass a valid regex up to 1000 characters.');

            return self::FAILURE;
        }

        $site = MonitoredSite::find((int) $identifier);

        if (! $site) {
            $this->error('Site not found: '.$identifier);

            return self::FAILURE;
        }

        if (! $clear && $site->source_type !== 'html') {
            $this->error('Article URL patterns can only be used with HTML sources.');

            return self::FAILURE;
        }

        $site->forceFill(['article_url_pattern' => $clear ? null : $pattern])->save();

        if ($clear) {
            $this->info("Article URL pattern cleared for {$site->name} (ID: {$site->id})");
        } else {
            $this->info("Article URL pattern set for {$site->name} (ID: {$site->id}): {$pattern}");
        }

        return self::SUCCESS;
    }
}
