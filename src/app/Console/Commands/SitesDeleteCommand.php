<?php

namespace App\Console\Commands;

use App\Models\MonitoredSite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('sites:delete
    {id : Site ID}
    {--force : Delete without confirmation}')]
#[Description('Delete a monitored site and all of its collected articles')]
class SitesDeleteCommand extends Command
{
    public function handle(): int
    {
        $identifier = trim((string) $this->argument('id'));

        if (! ctype_digit($identifier) || (int) $identifier < 1) {
            $this->error('Site ID must be a positive integer.');

            return self::FAILURE;
        }

        $site = MonitoredSite::find((int) $identifier);

        if (! $site) {
            $this->error('Site not found: '.$identifier);

            return self::FAILURE;
        }

        $articleCount = $site->articles()->count();
        $this->line("Site: {$site->name} (ID: {$site->id})");
        $this->line("Base URL: {$site->base_url}");
        $this->line("Articles to delete: {$articleCount}");

        if (! $this->option('force') && ! $this->confirm('Delete this site and all related articles?')) {
            $this->warn('Deletion cancelled.');

            return self::SUCCESS;
        }

        DB::transaction(static fn (): bool => $site->delete());

        $this->info("Site deleted: {$site->name} (ID: {$site->id}); articles deleted: {$articleCount}");

        return self::SUCCESS;
    }
}
