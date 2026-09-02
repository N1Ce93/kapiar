<?php

namespace App\Console\Commands;

use App\Jobs\CheckGmailJob;
use App\Services\Monitoring\GmailCheckRunner;
use App\Services\Monitoring\GmailMonitoringControl;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('gmail:resume')]
#[Description('Resume paused Gmail monitoring and dispatch a check')]
class GmailResumeCommand extends Command
{
    public function handle(GmailMonitoringControl $control): int
    {
        if (! config('services.gmail.monitoring_enabled')) {
            $this->error('Gmail monitoring is disabled. Set GMAIL_MONITORING_ENABLED=true before resuming it.');

            return self::FAILURE;
        }

        $lock = Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->warn('A Gmail check is still running. Try the resume command again later.');

            return self::FAILURE;
        }

        try {
            $pausedState = $control->state();

            if (! $control->resume()) {
                $this->warn('Gmail monitoring is not paused.');

                return self::SUCCESS;
            }
        } finally {
            $lock->release();
        }

        try {
            CheckGmailJob::dispatch();
        } catch (Throwable $exception) {
            (new UniqueLock(app(CacheRepository::class)))->release(new CheckGmailJob);
            $control->restorePause($pausedState);
            report($exception);
            $this->error('Gmail monitoring remains paused because the immediate check could not be dispatched.');

            return self::FAILURE;
        }

        $this->info('Gmail monitoring resumed and an immediate check was dispatched.');

        return self::SUCCESS;
    }
}
