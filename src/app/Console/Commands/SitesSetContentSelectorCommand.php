<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

#[Signature('sites:set-content-selector
    {site : Site ID, name, host, or base URL}
    {selector? : CSS selector for the article content root}
    {--clear : Remove the configured selector}')]
#[Description('Set or clear the CSS selector used to extract article content for a site')]
class SitesSetContentSelectorCommand extends Command
{
    public function handle(): int
    {
        $identifier = trim((string) $this->argument('site'));
        $selector = trim((string) ($this->argument('selector') ?? ''));
        $clear = (bool) $this->option('clear');

        if ($clear && $selector !== '') {
            $this->error('Do not pass a selector together with --clear.');

            return self::FAILURE;
        }

        if (! $clear && $selector === '') {
            $this->error('Pass a CSS selector or use --clear.');

            return self::FAILURE;
        }

        if (! $clear) {
            try {
                (new CssSelectorConverter)->toXPath($selector);
            } catch (Throwable $exception) {
                $this->error('Invalid CSS selector: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        $site = $this->site($identifier);

        if (! $site) {
            $this->error('Site not found: '.$identifier);

            return self::FAILURE;
        }

        $site->forceFill(['content_selector' => $clear ? null : $selector])->save();

        if ($clear) {
            $this->info("Content selector cleared for {$site->name} (ID: {$site->id})");
        } else {
            $this->info("Content selector set for {$site->name} (ID: {$site->id}): {$selector}");
        }

        return self::SUCCESS;
    }

    private function site(string $identifier): ?MonitoredSite
    {
        if (ctype_digit($identifier)) {
            return MonitoredSite::find((int) $identifier);
        }

        if (str_contains($identifier, '.')) {
            $host = UrlHelper::host(UrlHelper::normalizeBaseUrl($identifier));

            return MonitoredSite::query()
                ->where('base_url', 'like', '%'.$host.'%')
                ->orderBy('id')
                ->first();
        }

        return MonitoredSite::query()->where('name', $identifier)->first();
    }
}
