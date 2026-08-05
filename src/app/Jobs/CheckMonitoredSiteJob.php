<?php

namespace App\Jobs;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CheckMonitoredSiteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const FAILURE_LIMIT = 4;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $siteId,
        public readonly int $limit = 20,
        public readonly bool $analyze = true,
        public readonly bool $notify = true,
    ) {
        $this->onQueue('sources');
    }

    public function uniqueId(): string
    {
        return 'monitored-site:'.$this->siteId;
    }

    public function handle(ArticleMonitorService $monitorService, TelegramNotifier $notifier): void
    {
        $site = MonitoredSite::query()->whereKey($this->siteId)->where('enabled', true)->first();

        if (! $site) {
            return;
        }

        try {
            $monitorService->ingestSite($site, $this->limit, backfill: false, analyze: $this->analyze, notify: $this->notify);
            $this->recordSuccess($site);
        } catch (Throwable $exception) {
            $this->recordFailure($site, $exception, $notifier);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $site = MonitoredSite::query()->find($this->siteId);

        if ($site) {
            $this->recordFailure($site, $exception, app(TelegramNotifier::class));
        }
    }

    private function recordSuccess(MonitoredSite $site): void
    {
        $site->forceFill([
            'consecutive_failures' => 0,
            'last_success_at' => now(),
            'last_error_at' => null,
            'last_error' => null,
        ])->save();
    }

    private function recordFailure(MonitoredSite $site, ?Throwable $exception, TelegramNotifier $notifier): void
    {
        $failures = min(255, ((int) $site->consecutive_failures) + 1);
        $message = $this->errorMessage($exception);
        $updates = [
            'last_checked_at' => now(),
            'consecutive_failures' => $failures,
            'last_error_at' => now(),
            'last_error' => $message,
        ];

        if ($failures >= self::FAILURE_LIMIT && $site->enabled) {
            $updates += [
                'enabled' => false,
                'disabled_at' => now(),
                'disabled_reason' => 'auto-disabled after '.$failures.' consecutive failures',
            ];
        }

        $site->forceFill($updates)->save();

        if (($updates['enabled'] ?? true) === false) {
            $notifier->sendSourceDisabled('site', $site->name, $message);
        }
    }

    private function errorMessage(?Throwable $exception): string
    {
        if (! $exception) {
            return 'Source check failed without exception details.';
        }

        return mb_substr($this->redact($exception::class.': '.$exception->getMessage()), 0, 4000, 'UTF-8');
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
