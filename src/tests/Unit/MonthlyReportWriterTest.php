<?php

namespace Tests\Unit;

use App\Services\Reporting\MonthlyReportWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

class MonthlyReportWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = storage_path('framework/testing/monthly-reports');
        File::ensureDirectoryExists($directory);
        $this->path = $directory.'/'.uniqid('report_', true).'.xlsx';
    }

    protected function tearDown(): void
    {
        File::delete($this->path);

        parent::tearDown();
    }

    public function test_it_writes_sites_and_telegram_sheets_with_headers_and_totals(): void
    {
        app(MonthlyReportWriter::class)->write($this->path, [
            'sites' => [[
                'name' => '=Example Site',
                'items' => [[
                    'date' => Carbon::parse('2026-08-20 10:00:00'),
                    'name' => '=Example article',
                    'url' => '=https://example.com/article',
                    'keywords' => '=alpha, beta',
                ]],
            ]],
            'telegram' => [],
        ]);

        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        $reader->open($this->path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];

            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            $sheets[$sheet->getName()] = $rows;
        }

        $reader->close();

        $this->assertSame(['Sites', 'Telegram'], array_keys($sheets));
        $this->assertSame(array_values(MonthlyReportWriter::COLUMNS), $sheets['Sites'][0]);
        $this->assertSame([1, '=Example Site', 1, '', '', '', ''], $sheets['Sites'][1]);
        $this->assertInstanceOf(\DateTimeInterface::class, $sheets['Sites'][2][3]);
        $this->assertSame('=Example article', $sheets['Sites'][2][4]);
        $this->assertSame('=https://example.com/article', $sheets['Sites'][2][5]);
        $this->assertSame('=alpha, beta', $sheets['Sites'][2][6]);
        $this->assertSame(['', 'Total', 1, '', '', '', ''], $sheets['Sites'][4]);

        $this->assertSame(array_values(MonthlyReportWriter::COLUMNS), $sheets['Telegram'][0]);
        $this->assertSame(['', 'Total', 0, '', '', '', ''], $sheets['Telegram'][2]);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->path));
        $siteXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertIsString($siteXml);
        $this->assertStringNotContainsString('<f', $siteXml);
    }
}
