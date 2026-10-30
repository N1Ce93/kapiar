<?php

namespace App\Console\Commands;

use App\Services\Monitoring\GmailCheckAlreadyRunningException;
use App\Services\Monitoring\GmailCheckRunner;
use App\Services\Monitoring\GmailMonitoringControl;
use App\Services\Monitoring\GmailMonitorService;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('gmail:resume')]
#[Description('Check Gmail and send new notifications synchronously, then resume monitoring on success')]
class GmailResumeCommand extends Command
{
    public function handle(
        GmailCheckRunner $runner,
        GmailMonitorService $monitor,
        GmailMonitoringControl $control,
    ): int {
        if (! config('services.gmail.monitoring_enabled')) {
            $this->error('Gmail monitoring is disabled. Set GMAIL_MONITORING_ENABLED=true before resuming it.');

            return self::FAILURE;
        }

        $this->info('Checking Gmail and sending new matching notifications...');

        try {
            $stats = $runner->run(function (Closure $heartbeat) use ($monitor, $control): array {
                try {
                    $stats = $monitor->check($heartbeat);
                    $heartbeat();
                    $control->resume();

                    return $stats;
                } catch (Throwable $exception) {
                    $control->pause($exception);

                    throw $exception;
                }
            }, allowPaused: true);
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
