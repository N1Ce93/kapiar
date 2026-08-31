<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

class MonthlyReportWriter
{
    public const COLUMNS = [
        'number' => 'N',
        'source_name' => 'Name',
        'count' => 'Count',
        'date' => 'Date',
        'publication_name' => 'Name',
        'url' => 'Url',
        'keywords' => 'Words',
    ];

    public const SHEETS = [
        'sites' => 'Sites',
        'telegram' => 'Telegram',
    ];

    /**
     * @param array{
     *     sites:list<array{name:string,items:list<array{date:Carbon,name:string,url:string,keywords:string}>}>,
     *     telegram:list<array{name:string,items:list<array{date:Carbon,name:string,url:string,keywords:string}>}>
     * } $report
     */
    public function write(string $path, array $report): void
    {
        $options = new Options;
        $options->DEFAULT_ROW_STYLE
            ->setFontName('Arial')
            ->setFontSize(10)
            ->setCellVerticalAlignment(CellVerticalAlignment::TOP)
            ->setShouldWrapText();

        $writer = new Writer($options);

        try {
            $writer->openToFile($path);
            $this->writeSheet($writer, $writer->getCurrentSheet(), self::SHEETS['sites'], $report['sites']);
            $this->writeSheet($writer, $writer->addNewSheetAndMakeItCurrent(), self::SHEETS['telegram'], $report['telegram']);
        } catch (Throwable $exception) {
            $writer->close();

            throw $exception;
        }

        $writer->close();
    }

    /**
     * @param  list<array{name:string,items:list<array{date:Carbon,name:string,url:string,keywords:string}>}>  $groups
     */
    private function writeSheet(Writer $writer, Sheet $sheet, string $name, array $groups): void
    {
        $sheet->setName($name);
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        $sheet->setColumnWidth(6, 1);
        $sheet->setColumnWidth(28, 2);
        $sheet->setColumnWidth(10, 3);
        $sheet->setColumnWidth(22, 4);
        $sheet->setColumnWidth(55, 5, 6);
        $sheet->setColumnWidth(34, 7);

        $writer->addRow(Row::fromValues(array_values(self::COLUMNS), $this->headerStyle()));

        $total = 0;

        foreach ($groups as $index => $group) {
            $count = count($group['items']);
            $total += $count;
            $writer->addRow(new Row([
                new NumericCell($index + 1, null),
                $this->textCell($group['name']),
                new NumericCell($count, null),
                new EmptyCell(null, null),
                new EmptyCell(null, null),
                new EmptyCell(null, null),
                new EmptyCell(null, null),
            ], $this->sourceStyle()));

            foreach ($group['items'] as $item) {
                $writer->addRow(new Row([
                    new EmptyCell(null, null),
                    new EmptyCell(null, null),
                    new EmptyCell(null, null),
                    new DateTimeCell($item['date'], $this->dateStyle()),
                    $this->textCell($item['name']),
                    $this->textCell($item['url']),
                    $this->textCell($item['keywords']),
                ]));
            }
        }

        $writer->addRow(Row::fromValues([null, null, null, null, null, null, null]));
        $writer->addRow(Row::fromValues([
            null,
            'Total',
            $total,
            null,
            null,
            null,
            null,
        ], $this->totalStyle()));
    }

    private function headerStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setBackgroundColor('D9EAF7')
            ->setCellVerticalAlignment(CellVerticalAlignment::TOP)
            ->setShouldWrapText();
    }

    private function sourceStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setBackgroundColor('F2F2F2')
            ->setCellVerticalAlignment(CellVerticalAlignment::TOP)
            ->setShouldWrapText();
    }

    private function dateStyle(): Style
    {
        return (new Style)->setFormat('yyyy-mm-dd hh:mm:ss');
    }

    private function textCell(string $value): StringCell
    {
        return new StringCell(mb_substr($value, 0, 32767, 'UTF-8'), null);
    }

    private function totalStyle(): Style
    {
        return (new Style)
            ->setFontBold()
            ->setBorder(new Border(new BorderPart(
                Border::TOP,
                width: Border::WIDTH_THIN,
            )));
    }
}
