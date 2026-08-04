<?php

namespace App\Console\Commands;

use App\Models\TelegramChannel;
use App\Services\Monitoring\TelegramChannelMonitorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram-channels:check
    {--channel= : Channel id, username, or URL}
    {--limit=50 : Maximum number of recent messages per channel}')]
#[Description('Check Telegram channels for new posts and notify on keyword hits')]
class TelegramChannelsCheckCommand extends Command
{
    public function handle(TelegramChannelMonitorService $monitorService): int
    {
        $channels = $this->channels();
        $limit = max(1, (int) $this->option('limit'));

        if ($channels->isEmpty()) {
            $this->warn('No Telegram channels found.');

            return self::SUCCESS;
        }

        foreach ($channels as $channel) {
            $this->info('Checking @'.$channel->username.'...');

            try {
                $stats = $monitorService->ingestChannel($channel, $limit, backfill: false, analyze: true, notify: true);
            } catch (Throwable $exception) {
                $this->error($this->telegramError($exception));
                continue;
            }

            $this->table(['Found', 'New', 'Already known', 'Analyzed', 'Hits', 'Telegram sent'], [[
                $stats['found'],
                $stats['created'],
                $stats['skipped'],
                $stats['analyzed'],
                $stats['hits'],
                $stats['sent'],
            ]]);
        }

        return self::SUCCESS;
    }

    private function channels()
    {
        $query = TelegramChannel::query()->where('enabled', true)->orderBy('id');
        $channel = $this->option('channel');

        if (! $channel) {
            return $query->get();
        }

        $channel = trim((string) $channel);

        if (is_numeric($channel)) {
            return $query->where('id', (int) $channel)->get();
        }

        $username = strtolower(trim(preg_replace('~^https?://t\.me/~i', '', $channel) ?: $channel, '@/'));

        return $query->where('username', $username)->get();
    }

    private function telegramError(Throwable $exception): string
    {
        return str_contains($exception->getMessage(), 'AUTH_KEY_UNREGISTERED')
            ? 'Telegram account is not authorized. Run: php artisan telegram:login'
            : $exception->getMessage();
    }
}
