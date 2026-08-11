<?php

namespace App\Jobs;

use App\Models\TelegramChannel;
use App\Services\Monitoring\TelegramChannelMonitorService;
use App\Services\Monitoring\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CheckTelegramChannelJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const FAILURE_LIMIT = 4;

    public const CIRCUIT_CACHE_KEY = 'telegram:monitoring:circuit-open';

    public const SESSION_LOCK_KEY = 'telegram:monitoring:session-lock';

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $channelId,
        public readonly int $limit = 5,
        public readonly bool $analyze = true,
        public readonly bool $notify = true,
    ) {
        $this->onQueue('telegram');
    }

    public function uniqueId(): string
    {
        return 'telegram-channel:'.$this->channelId;
    }

    public function handle(TelegramChannelMonitorService $monitorService, TelegramNotifier $notifier): void
    {
        $channel = TelegramChannel::query()->whereKey($this->channelId)->where('enabled', true)->first();

        if (! $channel) {
            return;
        }

        if (Cache::has(self::CIRCUIT_CACHE_KEY)) {
            return;
        }

        $lock = Cache::lock(self::SESSION_LOCK_KEY, $this->timeout + 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $monitorService->ingestChannel($channel, $this->limit, backfill: false, analyze: $this->analyze, notify: $this->notify);
            $this->recordSuccess($channel);
        } catch (Throwable $exception) {
            if ($this->isSystemicTelegramFailure($this->errorMessage($exception))) {
                Cache::put(self::CIRCUIT_CACHE_KEY, $this->errorMessage($exception), now()->addMinutes(10));
            }

            $this->recordFailure($channel, $exception, $notifier);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $channel = TelegramChannel::query()->find($this->channelId);

        if ($channel) {
            $this->recordFailure($channel, $exception, app(TelegramNotifier::class));
        }
    }

    private function recordSuccess(TelegramChannel $channel): void
    {
        $channel->forceFill([
            'consecutive_failures' => 0,
            'last_success_at' => now(),
            'last_error_at' => null,
            'last_error' => null,
        ])->save();
    }

    private function recordFailure(TelegramChannel $channel, ?Throwable $exception, TelegramNotifier $notifier): void
    {
        $failures = min(255, ((int) $channel->consecutive_failures) + 1);
        $message = $this->errorMessage($exception);
        $updates = [
            'last_checked_at' => now(),
            'consecutive_failures' => $failures,
            'last_error_at' => now(),
            'last_error' => $message,
        ];

        if ($failures >= self::FAILURE_LIMIT && $channel->enabled && $this->shouldAutoDisable($message)) {
            $updates += [
                'enabled' => false,
                'disabled_at' => now(),
                'disabled_reason' => 'auto-disabled after '.$failures.' consecutive failures',
            ];
        }

        $channel->forceFill($updates)->save();

        if (($updates['enabled'] ?? true) === false) {
            $notifier->sendSourceDisabled('telegram', $channel->id, '@'.$channel->username, $message);
        }
    }

    private function errorMessage(?Throwable $exception): string
    {
        if (! $exception) {
            return 'Source check failed without exception details.';
        }

        return mb_substr($this->redact($exception::class.': '.$exception->getMessage()), 0, 4000, 'UTF-8');
    }

    private function shouldAutoDisable(string $message): bool
    {
        return ! str_contains($message, 'AUTH_KEY_UNREGISTERED')
            && ! str_contains($message, 'Could not connect to MadelineProto')
            && ! str_contains($message, 'No file descriptors available')
            && ! str_contains($message, 'Sending on the channel failed')
            && ! str_contains($message, 'SerializationException')
            && ! str_contains($message, 'Telegram account is not authorized');
    }

    private function isSystemicTelegramFailure(string $message): bool
    {
        return str_contains($message, 'AUTH_KEY_UNREGISTERED')
            || str_contains($message, 'Could not connect to MadelineProto')
            || str_contains($message, 'No file descriptors available')
            || str_contains($message, 'Sending on the channel failed')
            || str_contains($message, 'SerializationException')
            || str_contains($message, 'Telegram account is not authorized')
            || str_contains($message, 'Fill TELEGRAM_API_ID and TELEGRAM_API_HASH');
    }

    private function redact(string $message): string
    {
        foreach (array_filter([
            (string) config('services.telegram.bot_token'),
            (string) config('services.telegram.api_hash'),
        ]) as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return preg_replace('~bot\d+:[A-Za-z0-9_-]+~', 'bot[redacted]', $message) ?? $message;
    }
}
