<?php

namespace App\Console\Commands;

use App\Models\TelegramChannel;
use App\Services\Monitoring\TelegramChannelMonitorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('telegram-channels:backfill
    {--channel= : Channel id, username, or URL}
    {--limit=500 : Maximum number of old messages per channel}
    {--analyze : Analyze old messages for keyword hits without Telegram notifications}')]
#[Description('Collect old Telegram channel posts so they are not sent as new notifications')]
class TelegramChannelsBackfillCommand extends Command
{
    public function handle(TelegramChannelMonitorService $monitorService): int
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

            foreach ($channels as $channel) {
                $this->info('Backfilling @'.$channel->username.'...');

                try {
                    $stats = $monitorService->ingestChannel($channel, $limit, backfill: true, analyze: (bool) $this->option('analyze'), notify: false);
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error($this->telegramError($exception));
                    continue;
                }

                $this->table(['Found', 'Saved', 'Already known', 'Analyzed', 'Hits'], [[
                    $stats['found'],
                    $stats['created'],
                    $stats['skipped'],
                    $stats['analyzed'],
                    $stats['hits'],
                ]]);
            }

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
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
