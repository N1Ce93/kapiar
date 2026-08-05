<?php

namespace App\Console\Commands;

use App\Jobs\CheckTelegramChannelJob;
use App\Models\TelegramChannel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('telegram-channels:dispatch-checks
    {--channel= : Channel id, username, or URL}
    {--limit=5 : Maximum number of recent messages per channel job}
    {--channels-limit= : Maximum number of channels to dispatch in this run}
    {--no-notify : Save and analyze new messages without Telegram notifications}')]
#[Description('Dispatch Telegram channel checks to the telegram queue')]
class TelegramChannelsDispatchChecksCommand extends Command
{
    public function handle(): int
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

        foreach ($channels as $channel) {
            $channel->forceFill(['last_queued_at' => now()])->save();

            CheckTelegramChannelJob::dispatch(
                channelId: $channel->id,
                limit: $limit,
                analyze: true,
                notify: ! $this->option('no-notify'),
            );
        }

        $this->info('Selected Telegram channel check jobs: '.$channels->count());

        return self::SUCCESS;
    }

    private function channels()
    {
        $query = TelegramChannel::query()->where('enabled', true);
        $channel = $this->option('channel');

        if (! $channel) {
            $query->orderBy('last_queued_at')->orderBy('last_checked_at')->orderBy('id');

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
