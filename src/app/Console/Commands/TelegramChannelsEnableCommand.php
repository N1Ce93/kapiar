<?php

namespace App\Console\Commands;

use App\Models\TelegramChannel;
use App\Services\Monitoring\TelegramChannelUrl;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('telegram-channels:enable
    {channel : Channel ID, @username, username, or https://t.me/username}')]
#[Description('Enable monitoring for a disabled Telegram channel')]
class TelegramChannelsEnableCommand extends Command
{
    public function handle(): int
    {
        $identifier = trim((string) $this->argument('channel'));

        if (ctype_digit($identifier)) {
            $id = (int) $identifier;

            if ($id < 1) {
                $this->error('Telegram channel ID must be a positive integer.');

                return self::FAILURE;
            }

            $channel = TelegramChannel::find($id);
        } else {
            try {
                $parsed = TelegramChannelUrl::parse($identifier);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $channel = TelegramChannel::query()->where('username', $parsed['username'])->first();
        }

        if (! $channel) {
            $this->error('Telegram channel not found: '.$identifier);

            return self::FAILURE;
        }

        if ($channel->enabled) {
            $this->warn("Telegram channel is already enabled: @{$channel->username} (ID: {$channel->id})");

            return self::SUCCESS;
        }

        $channel->forceFill([
            'enabled' => true,
            'consecutive_failures' => 0,
            'last_error_at' => null,
            'last_error' => null,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();

        $this->info("Telegram channel enabled: @{$channel->username} (ID: {$channel->id})");

        return self::SUCCESS;
    }
}
