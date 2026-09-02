<?php

namespace App\Console\Commands;

use App\Jobs\CheckGmailJob;
use App\Services\Monitoring\GmailMonitoringControl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gmail:dispatch-check')]
#[Description('Dispatch the Gmail monitor to the email queue')]
class GmailDispatchChecksCommand extends Command
{
    public function handle(GmailMonitoringControl $control): int
    {
        if (! config('services.gmail.monitoring_enabled')) {
            $this->warn('Gmail monitoring is disabled. Set GMAIL_MONITORING_ENABLED=true to dispatch checks.');

            return self::SUCCESS;
        }

        if ($control->isPaused()) {
            $this->warn('Gmail monitoring is paused. Run gmail:resume after fixing the error.');

            return self::SUCCESS;
        }

        CheckGmailJob::dispatch();
        $this->info('Gmail check dispatch requested. Duplicate queued or running jobs are ignored.');

        return self::SUCCESS;
    }
}
