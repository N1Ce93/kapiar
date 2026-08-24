<?php

namespace App\Console\Commands;

use App\Services\Monitoring\GmailCheckAlreadyRunningException;
use App\Services\Monitoring\GmailCheckRunner;
use App\Services\Monitoring\GmailMonitorService;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('gmail:check')]
#[Description('Run the Gmail monitor synchronously')]
class GmailCheckCommand extends Command
{
    public function handle(GmailCheckRunner $runner, GmailMonitorService $monitor): int
    {
        try {
            $stats = $runner->run(fn (Closure $heartbeat): array => $monitor->check($heartbeat));
        } catch (GmailCheckAlreadyRunningException $exception) {
            $this->warn($exception->getMessage());

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($stats['initialized']) {
            $this->info('Gmail monitoring initialized. Existing messages were not processed.');

            return self::SUCCESS;
        }

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

        return self::SUCCESS;
    }
}
