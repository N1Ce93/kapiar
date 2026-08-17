<?php

namespace App\Console\Commands;

use App\Jobs\CheckTelegramChannelJob;
use App\Models\TelegramChannel;
use App\Services\Monitoring\SourceHealthService;
use App\Services\Monitoring\TelegramChannelMonitorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('telegram-channels:check
    {--channel= : Channel id, username, or URL}
    {--limit=50 : Maximum number of recent messages per channel}
    {--channels-limit= : Maximum number of channels to check in this run}
    {--no-notify : Save and analyze new messages without Telegram notifications}')]
#[Description('Check Telegram channels for new posts and notify on keyword hits')]
class TelegramChannelsCheckCommand extends Command
{
    public function handle(TelegramChannelMonitorService $monitorService, SourceHealthService $healthService): int
    {
        $channels = $this->channels();
        $limit = max(1, (int) $this->option('limit'));
        $lock = Cache::lock('telegram:monitoring:session-lock', 900);

        if (! $lock->get()) {
            $this->error('Telegram session is currently used by another process. Stop marketing-telegram-queue or wait for the current job to finish.');

            return self::FAILURE;
        }

        try {
            if ($channels->isEmpty()) {
                $this->warn('No Telegram channels found.');

                return self::SUCCESS;
            }

            $failed = 0;
            $bypassSchedule = $this->option('channel') !== null;

            foreach ($channels as $channel) {
                $claimToken = $healthService->reserveCheck($channel, $bypassSchedule);

                if ($claimToken === null) {
                    $this->warn('Telegram channel check is already pending or not due: @'.$channel->username);

                    continue;
                }

                $this->info('Checking @'.$channel->username.'...');

                try {
                    try {
                        $stats = $monitorService->ingestChannel($channel, $limit, backfill: false, analyze: true, notify: ! $this->option('no-notify'));
                    } catch (Throwable $exception) {
                        $failed++;
                        $systemicFailure = $healthService->isSystemicTelegramFailure($exception);

                        if ($systemicFailure) {
                            Cache::put(CheckTelegramChannelJob::CIRCUIT_CACHE_KEY, $healthService->errorMessage($exception), now()->addMinutes(10));
                        } else {
                            $healthService->recordFailure($channel, $exception, $claimToken);
                        }

                        $this->error($this->telegramError($exception));

                        if ($systemicFailure) {
                            break;
                        }

                        continue;
                    }

                    $healthService->recordSuccess($channel, $claimToken);

                    $this->table(['Found', 'New', 'Already known', 'Analyzed', 'Hits', 'Telegram sent'], [[
                        $stats['found'],
                        $stats['created'],
                        $stats['skipped'],
                        $stats['analyzed'],
                        $stats['hits'],
                        $stats['sent'],
                    ]]);
                } finally {
                    $healthService->releaseCheck($channel, $claimToken);
                }
            }

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function channels()
    {
        $query = TelegramChannel::query()->where('enabled', true);
        $channel = $this->option('channel');

        if (! $channel) {
            $query->where(function ($query): void {
                $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
            })->where(function ($query): void {
                $query->whereNull('check_pending_at')->orWhere('check_pending_at', '<=', now()->subHours(SourceHealthService::CHECK_CLAIM_TIMEOUT_HOURS));
            })->orderBy('next_check_at')->orderBy('id');

            if ($this->option('channels-limit')) {
                $query->limit(max(1, (int) $this->option('channels-limit')));
            }

            return $query->get();
        }

        $channel = trim((string) $channel);

        if (is_numeric($channel)) {
            return $query->where('id', (int) $channel)->orderBy('id')->get();
        }

        $username = strtolower(trim(preg_replace('~^https?://t\.me/~i', '', $channel) ?: $channel, '@/'));

        return $query->where('username', $username)->orderBy('id')->get();
    }

    private function telegramError(Throwable $exception): string
    {
        return str_contains($exception->getMessage(), 'AUTH_KEY_UNREGISTERED')
            ? 'Telegram account is not authorized. Run: php artisan telegram:login'
            : $exception->getMessage();
    }
}
