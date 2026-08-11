<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use App\Services\Monitoring\UrlHelper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sites:enable
    {site : Site ID or base URL}')]
#[Description('Enable monitoring for a disabled site')]
class SitesEnableCommand extends Command
{
    public function handle(): int
    {
        $identifier = trim((string) $this->argument('site'));

        if (ctype_digit($identifier)) {
            $id = (int) $identifier;

            if ($id < 1) {
                $this->error('Site ID must be a positive integer.');

                return self::FAILURE;
            }

            $site = MonitoredSite::find($id);
        } else {
            $url = preg_replace_callback(
                '~^https?://~i',
                static fn (array $matches): string => strtolower($matches[0]),
                $identifier,
            ) ?? $identifier;
            $baseUrl = UrlHelper::normalizeBaseUrl($url);

            if (! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $this->error('Invalid site identifier. Use a positive ID or a valid HTTP(S) URL.');

                return self::FAILURE;
            }

            $site = MonitoredSite::query()->where('base_url', $baseUrl)->first();
        }

        if (! $site) {
            $this->error('Site not found: '.$identifier);

            return self::FAILURE;
        }

        if ($site->enabled) {
            $this->warn("Site is already enabled: {$site->name} (ID: {$site->id})");

            return self::SUCCESS;
        }

        $site->forceFill([
            'enabled' => true,
            'consecutive_failures' => 0,
            'last_error_at' => null,
            'last_error' => null,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();

        $this->info("Site enabled: {$site->name} (ID: {$site->id})");

        return self::SUCCESS;
    }
}
