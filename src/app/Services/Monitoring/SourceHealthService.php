<?php

namespace App\Services\Monitoring;

use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SourceHealthService
{
    public const SITE_INTERVAL_MINUTES = 30;

    public const TELEGRAM_INTERVAL_MINUTES = 10;

    public const CHECK_CLAIM_TIMEOUT_HOURS = 25;

    private const PAUSE_AFTER_FAILURES = 3;

    private const PERMANENT_FAILURE_CONFIRMATION_HOURS = 4;

    private const ERROR_TYPE_TEMPORARY = 'temporary';

    private const ERROR_TYPE_PERMANENT = 'permanent';

    public function __construct(private readonly TelegramNotifier $notifier) {}

    public function reserveCheck(MonitoredSite|TelegramChannel $source, bool $bypassSchedule = false): ?string
    {
        return DB::transaction(function () use ($source, $bypassSchedule): ?string {
            $lockedSource = $this->lockSource($source);
            $now = now();

            if (! $lockedSource?->enabled
                || (! $bypassSchedule && $lockedSource->next_check_at && $lockedSource->next_check_at->greaterThan($now))
                || ($lockedSource->check_pending_at && $lockedSource->check_pending_at->greaterThan($now->copy()->subHours(self::CHECK_CLAIM_TIMEOUT_HOURS)))) {
                return null;
            }

            $claimToken = (string) Str::uuid();
            $lockedSource->forceFill([
                'last_queued_at' => $now,
                'check_pending_at' => $now,
                'check_claim_token' => $claimToken,
            ])->save();

            return $claimToken;
        });
    }

    public function releaseCheck(MonitoredSite|TelegramChannel $source, ?string $claimToken): void
    {
        if ($claimToken === null) {
            return;
        }

        $query = $source->newQuery()->whereKey($source->getKey());
        $query->where('check_claim_token', $claimToken)->update([
            'check_pending_at' => null,
            'check_claim_token' => null,
        ]);
    }

    public function recordSuccess(MonitoredSite|TelegramChannel $source, ?string $claimToken = null): void
    {
        DB::transaction(function () use ($source, $claimToken): void {
            $lockedSource = $this->lockSource($source);

            if (! $lockedSource?->enabled || ! $this->ownsClaim($lockedSource, $claimToken)) {
                return;
            }

            $lockedSource->forceFill([
                'consecutive_failures' => 0,
                'last_checked_at' => now(),
                'next_check_at' => now()->addMinutes($this->normalIntervalMinutes($lockedSource)),
                'last_success_at' => now(),
                'last_error_at' => null,
                'last_error' => null,
                'last_error_type' => null,
                'paused_at' => null,
                'check_pending_at' => null,
                'check_claim_token' => null,
            ])->save();
        });
    }

    public function recordFailure(MonitoredSite|TelegramChannel $source, ?Throwable $exception, ?string $claimToken = null): void
    {
        $message = $this->errorMessage($exception);
        $disabledSource = DB::transaction(function () use ($source, $message, $claimToken): ?array {
            $lockedSource = $this->lockSource($source);

            if (! $lockedSource?->enabled || ! $this->ownsClaim($lockedSource, $claimToken)) {
                return null;
            }

            $now = now();
            $previousFailures = (int) $lockedSource->consecutive_failures;
            $failures = min(255, $previousFailures + 1);
            $errorType = $this->errorType($lockedSource, $message);
            $permanentFailureConfirmed = $failures > self::PAUSE_AFTER_FAILURES
                && $errorType === self::ERROR_TYPE_PERMANENT
                && $lockedSource->last_error_type === self::ERROR_TYPE_PERMANENT
                && $lockedSource->last_error_at
                && $lockedSource->last_error_at->lessThanOrEqualTo($now->copy()->subHours(self::PERMANENT_FAILURE_CONFIRMATION_HOURS));
            $updates = [
                'last_checked_at' => $now,
                'consecutive_failures' => $failures,
                'last_error_at' => $now,
                'last_error' => $message,
                'last_error_type' => $errorType,
                'check_pending_at' => null,
                'check_claim_token' => null,
            ];

            if ($permanentFailureConfirmed) {
                $updates += [
                    'enabled' => false,
                    'next_check_at' => null,
                    'paused_at' => null,
                    'disabled_at' => $now,
                    'disabled_reason' => sprintf(
                        'permanent source error confirmed after a %d-hour pause',
                        self::PERMANENT_FAILURE_CONFIRMATION_HOURS,
                    ),
                ];
            } else {
                $updates['next_check_at'] = match ($failures) {
                    1 => $now->copy()->addMinutes(30),
                    2 => $now->copy()->addHours(2),
                    3 => $now->copy()->addHours(4),
                    default => $now->copy()->addHours(6),
                };

                if ($failures >= self::PAUSE_AFTER_FAILURES && $lockedSource->paused_at === null) {
                    $updates['paused_at'] = $now;
                }
            }

            $lockedSource->forceFill($updates)->save();

            return $permanentFailureConfirmed ? $this->sourceIdentity($lockedSource) : null;
        });

        if ($disabledSource) {
            $this->notifier->sendSourceDisabled(
                $disabledSource['type'],
                $disabledSource['id'],
                $disabledSource['name'],
                $message,
            );
        }
    }

    public function isSystemicTelegramFailure(?Throwable $exception): bool
    {
        $message = mb_strtolower($this->errorMessage($exception), 'UTF-8');

        foreach ([
            'auth_key_unregistered',
            'could not connect to madelineproto',
            'no file descriptors available',
            'sending on the channel failed',
            'serializationexception',
            'telegram account is not authorized',
            'fill telegram_api_id and telegram_api_hash',
            'flood_wait',
            'connection refused',
            'connection timed out',
        ] as $systemicError) {
            if (str_contains($message, $systemicError)) {
                return true;
            }
        }

        return false;
    }

    public function errorMessage(?Throwable $exception): string
    {
        if (! $exception) {
            return 'Source check failed without exception details.';
        }

        return mb_substr($this->redact($exception::class.': '.$exception->getMessage()), 0, 4000, 'UTF-8');
    }

    private function lockSource(MonitoredSite|TelegramChannel $source): MonitoredSite|TelegramChannel|null
    {
        return $source->newQuery()->lockForUpdate()->find($source->getKey());
    }

    private function ownsClaim(MonitoredSite|TelegramChannel $source, ?string $claimToken): bool
    {
        if ($claimToken === null) {
            return $source->check_claim_token === null;
        }

        return $source->check_claim_token === $claimToken;
    }

    private function normalIntervalMinutes(MonitoredSite|TelegramChannel $source): int
    {
        return $source instanceof MonitoredSite
            ? self::SITE_INTERVAL_MINUTES
            : self::TELEGRAM_INTERVAL_MINUTES;
    }

    private function errorType(MonitoredSite|TelegramChannel $source, string $message): string
    {
        if ($source instanceof MonitoredSite) {
            return preg_match('/returned HTTP (404|410)\b/i', $message) === 1
                ? self::ERROR_TYPE_PERMANENT
                : self::ERROR_TYPE_TEMPORARY;
        }

        $message = mb_strtoupper($message, 'UTF-8');

        foreach (['USERNAME_INVALID', 'USERNAME_NOT_OCCUPIED', 'CHANNEL_INVALID', 'CHANNEL_PRIVATE', 'PEER_ID_INVALID'] as $permanentError) {
            if (str_contains($message, $permanentError)) {
                return self::ERROR_TYPE_PERMANENT;
            }
        }

        return self::ERROR_TYPE_TEMPORARY;
    }

    /** @return array{type:string,id:int,name:string} */
    private function sourceIdentity(MonitoredSite|TelegramChannel $source): array
    {
        return [
            'type' => $source instanceof MonitoredSite ? 'site' : 'telegram',
            'id' => $source->id,
            'name' => $source instanceof MonitoredSite ? $source->name : '@'.$source->username,
        ];
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
