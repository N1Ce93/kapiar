<?php

namespace App\Services\Monitoring;

use App\Models\GmailMonitorControl;
use Illuminate\Support\Str;
use Throwable;

class GmailMonitoringControl
{
    public function __construct(
        private readonly TelegramNotifier $notifier,
    ) {}

    public function state(): GmailMonitorControl
    {
        return GmailMonitorControl::query()->findOrFail(GmailMonitorControl::SINGLETON_ID);
    }

    public function isPaused(): bool
    {
        return GmailMonitorControl::query()
            ->whereKey(GmailMonitorControl::SINGLETON_ID)
            ->whereNotNull('paused_at')
            ->exists();
    }

    public function pause(?Throwable $exception): bool
    {
        $now = now();
        $incidentId = (string) Str::uuid();
        $error = mb_substr(
            trim((string) ($exception?->getMessage() ?: 'Gmail check failed without an error message.')),
            0,
            4000,
            'UTF-8',
        );

        $claimed = GmailMonitorControl::query()
            ->whereKey(GmailMonitorControl::SINGLETON_ID)
            ->whereNull('paused_at')
            ->update([
                'incident_id' => $incidentId,
                'paused_at' => $now,
                'last_error_at' => $now,
                'last_error' => $error,
                'alert_attempted_at' => $now,
                'alert_delivered_at' => null,
                'updated_at' => $now,
            ]);

        if ($claimed !== 1) {
            return false;
        }

        if ($this->notifier->sendGmailMonitoringPaused($error)) {
            GmailMonitorControl::query()
                ->whereKey(GmailMonitorControl::SINGLETON_ID)
                ->where('incident_id', $incidentId)
                ->update([
                    'alert_delivered_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return true;
    }

    public function resume(): bool
    {
        return GmailMonitorControl::query()
            ->whereKey(GmailMonitorControl::SINGLETON_ID)
            ->whereNotNull('paused_at')
            ->update([
                'paused_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    public function restorePause(GmailMonitorControl $state): bool
    {
        if ($state->paused_at === null || $state->incident_id === null) {
            return false;
        }

        return GmailMonitorControl::query()
            ->whereKey(GmailMonitorControl::SINGLETON_ID)
            ->where('incident_id', $state->incident_id)
            ->whereNull('paused_at')
            ->update([
                'incident_id' => $state->incident_id,
                'paused_at' => $state->paused_at,
                'last_error_at' => $state->last_error_at,
                'last_error' => $state->last_error,
                'alert_attempted_at' => $state->alert_attempted_at,
                'alert_delivered_at' => $state->alert_delivered_at,
                'updated_at' => now(),
            ]) === 1;
    }
}
