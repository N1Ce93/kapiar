<?php

namespace App\Console\Commands;

use App\Services\Monitoring\TelegramNotifier;
use App\Services\Reporting\MonthlyReportDataService;
use App\Services\Reporting\MonthlyReportWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

#[Signature('reports:send-monthly {--month= : Month in YYYY-MM format; defaults to the previous month}')]
#[Description('Generate and send the monthly sites and Telegram report')]
class SendMonthlyReportCommand extends Command
{
    private const MONTH_NAMES = [
        1 => 'Январь',
        2 => 'Февраль',
        3 => 'Март',
        4 => 'Апрель',
        5 => 'Май',
        6 => 'Июнь',
        7 => 'Июль',
        8 => 'Август',
        9 => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    ];

    public function handle(
        MonthlyReportDataService $dataService,
        MonthlyReportWriter $writer,
        TelegramNotifier $notifier,
    ): int {
        $month = $this->resolveMonth();

        if ($month === null) {
            return self::INVALID;
        }

        $period = $month->format('Y-m');
        $filename = 'report_'.$period.'.xlsx';
        $directory = storage_path('app/private/reports');
        $path = $directory.'/'.Str::uuid().'_'.$filename;

        try {
            File::ensureDirectoryExists($directory);
            $writer->write($path, $dataService->forPeriod($month, $month->copy()->addMonth()));

            if (! $notifier->sendDocument($path, $filename, $this->caption($month))) {
                $this->error('Monthly report was generated but could not be sent to Telegram.');

                return self::FAILURE;
            }

            $this->info('Monthly report sent: '.$filename);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Monthly report send failed.', [
                'period' => $period,
                'error' => $exception->getMessage(),
            ]);
            $this->error('Monthly report send failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            File::delete($path);
        }
    }

    private function resolveMonth(): ?Carbon
    {
        $value = $this->option('month');

        if ($value === null || $value === '') {
            return now()->startOfMonth()->subMonth();
        }

        if (! is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            $this->error('The --month option must use YYYY-MM format.');

            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value.'-01', config('app.timezone'))->startOfDay();
    }

    private function caption(Carbon $month): string
    {
        return 'Отчет за месяц '.self::MONTH_NAMES[$month->month].' '.$month->year;
    }
}
