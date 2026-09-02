<?php

namespace App\Console\Commands;

use App\Models\GmailMonitorState;
use App\Services\Monitoring\GmailApiClient;
use App\Services\Monitoring\GmailMonitoringControl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('gmail:status')]
#[Description('Check Gmail OAuth access and monitoring state')]
class GmailStatusCommand extends Command
{
    public function handle(GmailApiClient $gmail, GmailMonitoringControl $control): int
    {
        $monitoring = $control->state();

        if ($monitoring->paused_at !== null) {
            $this->error('Gmail monitoring is paused.');
            $this->line('Paused at: '.$monitoring->paused_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'));
            $this->line('Reason: '.($monitoring->last_error ?: '-'));
            $this->line('Telegram alert delivered: '.($monitoring->alert_delivered_at ? 'yes' : 'no'));
            $this->line('Resume: php artisan gmail:resume');

            return self::FAILURE;
        }

        try {
            $profile = $gmail->profile();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $state = GmailMonitorState::query()->first();

        if ($state && strcasecmp($state->email_address, $profile['emailAddress']) !== 0) {
            $this->error('Configured OAuth account does not match the initialized Gmail account: '.$state->email_address);

            return self::FAILURE;
        }
        $this->info('Gmail OAuth connection is available.');
        $this->line('Account: '.$profile['emailAddress']);
        $this->line('Monitoring initialized: '.($state ? 'yes' : 'no'));
        $this->line('Last check: '.($state?->last_checked_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '-'));

        return self::SUCCESS;
    }
}
