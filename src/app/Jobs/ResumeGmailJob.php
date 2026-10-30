<?php

namespace App\Jobs;

use App\Services\Monitoring\GmailRecoveryService;
use App\Services\Monitoring\GmailResumeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ResumeGmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $recoveryId)
    {
        $this->onQueue('email');
    }

    public function handle(GmailRecoveryService $recovery, GmailResumeService $resume): void
    {
        if (! $recovery->start($this->recoveryId)) {
            return;
        }

        try {
            $stats = $resume->run(fn () => $recovery->assertRunning($this->recoveryId));
            $recovery->finish($this->recoveryId, $stats);
        } catch (Throwable $exception) {
            $recovery->finish($this->recoveryId, error: $exception->getMessage());

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Never release another check's runtime lock, or overwrite a newer request.
        app(GmailRecoveryService::class)->finish(
            $this->recoveryId,
            error: $exception?->getMessage() ?: 'Фонова перевірка Gmail завершилася помилкою.',
        );
    }
}
