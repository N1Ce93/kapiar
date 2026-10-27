<?php

namespace App\Services\Monitoring;

use App\Models\MonitoredSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SiteRecoveryService
{
    public function __construct(
        private readonly ArticleDiscoveryService $discovery,
        private readonly ArticleTextExtractor $extractor,
        private readonly SourceHealthService $health,
    ) {}

    public function checkAndEnable(MonitoredSite $site): void
    {
        $claimToken = DB::transaction(function () use ($site): string {
            $lockedSite = MonitoredSite::query()->lockForUpdate()->findOrFail($site->id);

            if ($lockedSite->enabled && $lockedSite->paused_at === null) {
                throw ValidationException::withMessages(['site' => 'Сайт уже увімкнено. Оновіть сторінку.']);
            }

            if ($lockedSite->check_pending_at?->greaterThan(now()->subHours(SourceHealthService::CHECK_CLAIM_TIMEOUT_HOURS))) {
                throw ValidationException::withMessages(['site' => 'Сайт уже перевіряється. Спробуйте пізніше.']);
            }

            $token = (string) Str::uuid();
            $lockedSite->forceFill([
                'check_pending_at' => now(),
                'check_claim_token' => $token,
            ])->save();
            $site->setRawAttributes($lockedSite->getAttributes(), true);

            return $token;
        });

        try {
            try {
                $items = $this->discovery->discover($site, 1);

                if ($items === []) {
                    throw new RuntimeException('Не знайдено публікацій у налаштованому джерелі.');
                }

                $article = $this->extractor->extract($items[0]['url'], $site->content_selector);

                if ($article === null || trim($article['text']) === '') {
                    throw new RuntimeException('Не вдалося прочитати текст статті: '.$items[0]['url']);
                }
            } catch (Throwable $exception) {
                throw ValidationException::withMessages([
                    'site' => 'Перевірка не пройшла. '.$this->health->errorMessage($exception),
                ]);
            }

            DB::transaction(function () use ($site, $claimToken): void {
                $lockedSite = MonitoredSite::query()->lockForUpdate()->findOrFail($site->id);

                if ($lockedSite->check_claim_token !== $claimToken) {
                    throw ValidationException::withMessages(['site' => 'Стан перевірки змінився. Оновіть сторінку та спробуйте ще раз.']);
                }

                $lockedSite->forceFill([
                    'enabled' => true,
                    'consecutive_failures' => 0,
                    'last_checked_at' => now(),
                    'last_success_at' => now(),
                    'next_check_at' => now(),
                    'last_error_at' => null,
                    'last_error' => null,
                    'last_error_type' => null,
                    'paused_at' => null,
                    'disabled_at' => null,
                    'disabled_reason' => null,
                    'check_pending_at' => null,
                    'check_claim_token' => null,
                ])->save();
            });
        } finally {
            $this->health->releaseCheck($site, $claimToken);
        }
    }
}
