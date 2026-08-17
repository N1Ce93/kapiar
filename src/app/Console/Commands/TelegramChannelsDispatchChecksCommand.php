<?php

namespace App\Console\Commands;

use App\Jobs\CheckTelegramChannelJob;
use App\Models\TelegramChannel;
use App\Services\Monitoring\SourceHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('telegram-channels:dispatch-checks
    {--channel= : Channel id, username, or URL}
    {--limit=5 : Maximum number of recent messages per channel job}
    {--channels-limit= : Maximum number of channels to dispatch in this run}
    {--no-notify : Save and analyze new messages without Telegram notifications}')]
#[Description('Dispatch Telegram channel checks to the telegram queue')]
class TelegramChannelsDispatchChecksCommand extends Command
{
    public function handle(SourceHealthService $healthService): int
    {
        if (! config('services.telegram.monitoring_enabled')) {
            $this->warn('Telegram monitoring is disabled. Set TELEGRAM_MONITORING_ENABLED=true to dispatch Telegram checks.');

            return self::SUCCESS;
        }

        if ($reason = Cache::get(CheckTelegramChannelJob::CIRCUIT_CACHE_KEY)) {
            $this->warn('Telegram checks are paused by circuit breaker: '.$reason);

            return self::SUCCESS;
        }

        $channels = $this->channels();
        $limit = max(1, (int) $this->option('limit'));

        if ($channels->isEmpty()) {
            $this->warn('No Telegram channels found.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $bypassSchedule = $this->option('channel') !== null;

        foreach ($channels as $channel) {
            $claimToken = $healthService->reserveCheck($channel, $bypassSchedule);

            if ($claimToken === null) {
                continue;
            }

            try {
                CheckTelegramChannelJob::dispatch(
                    channelId: $channel->id,
                    limit: $limit,
                    analyze: true,
                    notify: ! $this->option('no-notify'),
                    claimToken: $claimToken,
                );
            } catch (Throwable $exception) {
                $healthService->releaseCheck($channel, $claimToken);

                throw $exception;
            }

            $dispatched++;
        }

        $this->info('Selected Telegram channel check jobs: '.$dispatched);

        return self::SUCCESS;
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
            })->orderBy('next_check_at')->orderBy('last_queued_at')->orderBy('id');

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
}
