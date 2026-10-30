<?php

namespace App\Console\Commands;

use App\Services\Monitoring\GmailCheckAlreadyRunningException;
use App\Services\Monitoring\GmailResumeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('gmail:resume')]
#[Description('Check Gmail and send new notifications synchronously, then resume monitoring on success')]
class GmailResumeCommand extends Command
{
    public function handle(GmailResumeService $resume): int
    {
        if (! config('services.gmail.monitoring_enabled')) {
            $this->error('Gmail monitoring is disabled. Set GMAIL_MONITORING_ENABLED=true before resuming it.');

            return self::FAILURE;
        }

        $this->info('Checking Gmail and sending new matching notifications...');

        try {
            $stats = $resume->run();
        } catch (GmailCheckAlreadyRunningException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->error('Gmail monitoring was not activated. Fix the error and run gmail:resume again.');

            return self::FAILURE;
        }

        if ($stats['initialized']) {
            $this->info('Gmail monitoring initialized. Existing messages were not processed.');
        } else {
            $this->table(
                ['Found', 'Matched', 'Telegram sent', 'Completed', 'Pending', 'History recovered'],
                [[
                    $stats['found'],
                    $stats['matched'],
                    $stats['sent'],
                    $stats['completed'],
                    $stats['pending'],
                    $stats['recovered'] ? 'yes' : 'no',
                ]],
            );
        }

        $this->info('Gmail check completed successfully. Monitoring is active.');

        return self::SUCCESS;
    }
}
