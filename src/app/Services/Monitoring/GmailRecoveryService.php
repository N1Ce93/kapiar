<?php

namespace App\Services\Monitoring;

use App\Jobs\ResumeGmailJob;
use App\Models\GmailMonitorControl;
use App\Models\GmailMonitorState;
use App\Models\GmailProcessingMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GmailRecoveryService
{
    // Also bounds queue wait time, so an abandoned request cannot block recovery forever.
    public const STALE_SECONDS = GmailCheckRunner::LOCK_SECONDS;

    public function __construct(
        private readonly GmailMonitoringControl $control,
    ) {}

    public function status(): array
    {
        $this->expireStaleRequest();
        $control = $this->control->state();
        $state = GmailMonitorState::query()->first();
        $enabled = (bool) config('services.gmail.monitoring_enabled');
        $running = $this->isRunning();
        $busy = $running || in_array($control->recovery_status, ['queued', 'running'], true);
        $status = match (true) {
            ! $enabled => 'disabled',
            $running || $control->recovery_status === 'running' => 'running',
            $control->recovery_status === 'queued' => 'queued',
            $control->paused_at !== null => 'paused',
            $state === null => 'uninitialized',
            default => 'active',
        };

        return [
            'status' => $status,
            'enabled' => $enabled,
            'can_resume' => $enabled && ! $busy && ($control->paused_at !== null || $state === null),
            'account' => $state?->email_address,
            'last_checked_at' => $this->date($state?->last_checked_at),
            'paused_at' => $this->date($control->paused_at),
            'last_error_at' => $this->date($control->last_error_at),
            'last_error' => $control->last_error,
            'pending' => GmailProcessingMessage::query()->count(),
            'recovery' => [
                'id' => $control->recovery_id,
                'status' => $control->recovery_status,
                'requested_at' => $this->date($control->recovery_requested_at),
                'started_at' => $this->date($control->recovery_started_at),
                'finished_at' => $this->date($control->recovery_finished_at),
                'error' => $control->recovery_error,
                'stats' => $control->recovery_stats,
            ],
        ];
    }

    public function request(): void
    {
        if (! config('services.gmail.monitoring_enabled')) {
            $this->reject('Моніторинг Gmail вимкнено. Установіть GMAIL_MONITORING_ENABLED=true та оновіть кеш конфігурації.');
        }

        $driver = config('queue.connections.'.config('queue.default').'.driver');

        if (! in_array($driver, ['redis', 'database', 'beanstalkd', 'sqs'], true)) {
            $this->reject('Для фонової перевірки потрібна асинхронна черга та worker email. Налаштуйте QUEUE_CONNECTION=redis.');
        }

        $this->expireStaleRequest();
        $id = (string) Str::uuid();

        DB::transaction(function () use ($id): void {
            $control = GmailMonitorControl::query()->lockForUpdate()->findOrFail(GmailMonitorControl::SINGLETON_ID);

            if ($this->isRunning() || in_array($control->recovery_status, ['queued', 'running'], true)) {
                $this->reject('Перевірка Gmail уже виконується або очікує в черзі.');
            }

            if ($control->paused_at === null && GmailMonitorState::query()->exists()) {
                $this->reject('Моніторинг Gmail уже активний.');
            }

            $control->update([
                'recovery_id' => $id,
                'recovery_status' => 'queued',
                'recovery_requested_at' => now(),
                'recovery_started_at' => null,
                'recovery_finished_at' => null,
                'recovery_error' => null,
                'recovery_stats' => null,
            ]);
        });

        try {
            Bus::dispatch(new ResumeGmailJob($id));
        } catch (Throwable $exception) {
            $this->finish($id, error: 'Не вдалося поставити перевірку в чергу. Перевірте підключення до черги та повторіть спробу.');
            report($exception);
            $this->reject('Не вдалося поставити перевірку в чергу. Перевірте підключення до черги та повторіть спробу.');
        }
    }

    public function start(string $id): bool
    {
        return GmailMonitorControl::query()
            ->whereKey(GmailMonitorControl::SINGLETON_ID)
            ->where('recovery_id', $id)
            ->where('recovery_status', 'queued')
            ->where('recovery_requested_at', '>', now()->subSeconds(self::STALE_SECONDS))
            ->update(['recovery_status' => 'running', 'recovery_started_at' => now(), 'updated_at' => now()]) === 1;
    }

    public function finish(string $id, ?array $stats = null, ?string $error = null): void
    {
        // Conditional updates keep late failures from overwriting a newer recovery attempt.
        GmailMonitorControl::query()
            ->whereKey(GmailMonitorControl::SINGLETON_ID)
            ->where('recovery_id', $id)
            ->whereIn('recovery_status', ['queued', 'running'])
            ->update([
                'recovery_status' => $error === null ? 'succeeded' : 'failed',
                'recovery_finished_at' => now(),
                'recovery_stats' => $stats === null ? null : json_encode($stats, JSON_THROW_ON_ERROR),
                'recovery_error' => $error === null ? null : mb_substr($error, 0, 4000, 'UTF-8'),
                'updated_at' => now(),
            ]);
    }

    public function assertRunning(string $id): void
    {
        if (! GmailMonitorControl::query()
            ->whereKey(GmailMonitorControl::SINGLETON_ID)
            ->where('recovery_id', $id)
            ->where('recovery_status', 'running')
            ->exists()) {
            throw new RuntimeException('Запит на відновлення Gmail більше не актуальний. Оновіть стан.');
        }
    }

    private function expireStaleRequest(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $cutoff = now()->subSeconds(self::STALE_SECONDS);
        GmailMonitorControl::query()
            ->whereKey(GmailMonitorControl::SINGLETON_ID)
            ->whereIn('recovery_status', ['queued', 'running'])
            ->whereRaw('COALESCE(recovery_started_at, recovery_requested_at) <= ?', [$cutoff])
            ->update([
                'recovery_status' => 'failed',
                'recovery_finished_at' => now(),
                'recovery_error' => 'Час очікування перевірки вичерпано. Перевірте, чи працює worker email, та повторіть спробу.',
                'updated_at' => now(),
            ]);
    }

    private function isRunning(): bool
    {
        return Cache::lock(GmailCheckRunner::LOCK_KEY, GmailCheckRunner::LOCK_SECONDS)->isLocked();
    }

    private function date(?Carbon $date): ?string
    {
        return $date?->copy()->timezone('Europe/Kyiv')->format('d.m.Y H:i:s');
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['gmail' => $message]);
    }
}
