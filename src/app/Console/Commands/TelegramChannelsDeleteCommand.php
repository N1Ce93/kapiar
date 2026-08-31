<?php

namespace App\Console\Commands;

use App\Models\TelegramChannel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('telegram-channels:delete
    {id : Telegram channel ID}
    {--force : Delete without confirmation}')]
#[Description('Delete a monitored Telegram channel and all of its collected messages')]
class TelegramChannelsDeleteCommand extends Command
{
    public function handle(): int
    {
        $identifier = trim((string) $this->argument('id'));

        if (! ctype_digit($identifier) || (int) $identifier < 1) {
            $this->error('Telegram channel ID must be a positive integer.');

            return self::FAILURE;
        }

        $channel = TelegramChannel::find((int) $identifier);

        if (! $channel) {
            $this->error('Telegram channel not found: '.$identifier);

            return self::FAILURE;
        }

        $messageCount = $channel->messages()->count();
        $this->line("Telegram channel: @{$channel->username} (ID: {$channel->id})");
        $this->line("Messages to delete: {$messageCount}");

        if (! $this->option('force') && ! $this->confirm('Delete this Telegram channel and all related messages?')) {
            $this->warn('Deletion cancelled.');

            return self::SUCCESS;
        }

        DB::transaction(static fn (): bool => $channel->delete());

        $this->info("Telegram channel deleted: @{$channel->username} (ID: {$channel->id}); messages deleted: {$messageCount}");

        return self::SUCCESS;
    }
}
