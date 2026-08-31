<?php

namespace Tests\Feature\Console;

use App\Services\Reporting\MonthlyReportDataService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class SendMonthlyReportCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sends_the_previous_month_report_with_a_russian_caption_and_cleans_up(): void
    {
        Carbon::setTestNow('2026-09-01 08:00:00');
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1002354975882',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $directory = storage_path('app/private/reports');
        $filesBefore = File::glob($directory.'/*') ?: [];

        $this->mock(MonthlyReportDataService::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('forPeriod')
                ->once()
                ->withArgs(fn (Carbon $start, Carbon $end): bool => $start->format('Y-m-d H:i:s') === '2026-08-01 00:00:00'
                    && $end->format('Y-m-d H:i:s') === '2026-09-01 00:00:00')
                ->andReturn(['sites' => [], 'telegram' => []]);
        });

        $this->artisan('reports:send-monthly')
            ->expectsOutput('Monthly report sent: report_2026-08.xlsx')
            ->assertSuccessful();

        Http::assertSentCount(1);
        /** @var Request $request */
        $request = Http::recorded()->first()[0];
        $parts = collect($request->data())->keyBy('name');
        $this->assertTrue($request->hasFile('document', filename: 'report_2026-08.xlsx'));
        $this->assertSame('Отчет за месяц Август 2026', $parts->get('caption')['contents']);
        $this->assertSame($filesBefore, File::glob($directory.'/*') ?: []);
    }

    public function test_it_rejects_an_invalid_month(): void
    {
        Http::fake();

        $this->artisan('reports:send-monthly', ['--month' => '2026-13'])
            ->expectsOutput('The --month option must use YYYY-MM format.')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_it_cleans_up_the_generated_file_when_telegram_rejects_it(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1002354975882',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false])]);
        $directory = storage_path('app/private/reports');
        $filesBefore = File::glob($directory.'/*') ?: [];

        $this->mock(MonthlyReportDataService::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('forPeriod')
                ->once()
                ->andReturn(['sites' => [], 'telegram' => []]);
        });

        $this->artisan('reports:send-monthly', ['--month' => '2026-08'])
            ->expectsOutput('Monthly report was generated but could not be sent to Telegram.')
            ->assertFailed();

        $this->assertSame($filesBefore, File::glob($directory.'/*') ?: []);
    }

    public function test_monthly_report_is_scheduled_for_eight_in_the_morning_in_kyiv(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command, 'reports:send-monthly'));

        $this->assertNotNull($event);
        $this->assertSame('0 8 1 * *', $event->expression);
        $this->assertSame('Europe/Kyiv', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(120, $event->expiresAt);
    }
}
