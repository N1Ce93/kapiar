<?php

namespace App\Console\Commands;

use App\Models\TelegramChannel;
use App\Services\Monitoring\TelegramChannelMonitorService;
use App\Services\Monitoring\TelegramChannelUrl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram-channels:add
    {channel : Public channel @username or https://t.me/username}
    {--title= : Human-readable channel title}
    {--backfill-limit=500 : Number of old messages to save immediately}
    {--no-backfill : Add the channel without collecting old messages}')]
#[Description('Add a public Telegram channel and backfill old posts without notifications')]
class TelegramChannelsAddCommand extends Command
{
    public function handle(TelegramChannelMonitorService $monitorService): int
    {
        try {
            $parsed = TelegramChannelUrl::parse((string) $this->argument('channel'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $resolved = ['title' => null, 'telegram_peer' => $parsed['peer']];

        try {
            $resolved = $monitorService->resolveChannel($parsed['peer']);
        } catch (Throwable $exception) {
            $this->warn('Could not resolve channel through Telegram Client API: '.$exception->getMessage());
            $this->warn('Channel will be saved anyway. Run telegram:login if the account is not authorized yet.');
        }

        $channel = TelegramChannel::updateOrCreate(
            ['username' => $parsed['username']],
            [
                'title' => $this->option('title') ?: $resolved['title'] ?: $parsed['username'],
                'url' => $parsed['url'],
                'telegram_peer' => $resolved['telegram_peer'] ?: $parsed['peer'],
                'enabled' => true,
            ],
        );

        $this->info(($channel->wasRecentlyCreated ? 'Telegram channel added: ' : 'Telegram channel updated: ').'@'.$channel->username);

        if ($this->option('no-backfill')) {
            return self::SUCCESS;
        }

        try {
            $stats = $monitorService->ingestChannel($channel->fresh(), max(1, (int) $this->option('backfill-limit')), backfill: true, analyze: false, notify: false);
        } catch (Throwable $exception) {
            $this->error('Backfill failed: '.$this->telegramError($exception));
            $this->line('The channel is saved. Run telegram:login and then telegram-channels:backfill.');

            return self::FAILURE;
        }

        $this->table(['Found', 'Saved', 'Already known'], [[
            $stats['found'],
            $stats['created'],
            $stats['skipped'],
        ]]);

        return self::SUCCESS;
    }

    private function telegramError(Throwable $exception): string
    {
        return str_contains($exception->getMessage(), 'AUTH_KEY_UNREGISTERED')
            ? 'Telegram account is not authorized. Run: php artisan telegram:login'
            : $exception->getMessage();
    }
}
