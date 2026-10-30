<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Excel;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Ops\Application\Reports\ColumnType;
use Modules\Ops\Application\Reports\Report;
use Modules\Ops\Application\Reports\ReportColumn;
use Modules\Ops\Application\Reports\ReportMoney;
use Modules\Ops\Application\Reports\ReportSheet;
use Modules\Ops\Application\Reports\ReportWriter;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The reports' workbooks, by OpenSpout (the owner's choice, 2026-10-29): it writes each row to a temporary
 * file as it comes and zips them at the end, so a report's length never fills memory.
 *
 * Each sheet: the report's title, its store and days, the notes saying what the figures are, then the
 * headers - frozen, so they stay in sight - and the rows. Right to left in Arabic. A count is a number, an
 * amount a number with its currency's decimals (the currency in a column of its own), a day a date shown
 * `yyyy-mm-dd`. Every text is written as text, never read as a formula: a search phrase may begin with "=".
 */
final class OpenSpoutReportWriter implements ReportWriter
{
    private const string DAY = 'yyyy-mm-dd';

    private const string MONTH = 'yyyy-mm';

    /** @var array<int, Style> the amounts' styles by their decimals */
    private array $money = [];

    public function write(Report $report, string $path): void
    {
        // One font for every cell, the styled ones and the plain ones alike.
        $writer = new Writer(new Options(new Style));
        $writer->openToFile($path);

        try {
            foreach ($report->sheets as $index => $sheet) {
                $this->sheet($writer, $index === 0, $report, $sheet);
            }
        } finally {
            $writer->close();
        }
    }

    private function sheet(Writer $writer, bool $first, Report $report, ReportSheet $sheet): void
    {
        $current = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $current->setName($sheet->name);
        // The title, its line, the notes, a blank row, then the headers: freeze below them.
        $current->setSheetView(new SheetView(rightToLeft: $report->rightToLeft())->withFreezeRow(count($sheet->notes) + 5));
        foreach ($sheet->columns as $index => $column) {
            $current->setColumnWidth(self::width($column), $index + 1);
        }

        $writer->addRow(new Row([new StringCell($report->title, new Style(fontBold: true, fontSize: 14))]));
        $writer->addRow(new Row([new StringCell($report->subtitle, new Style(fontBold: true))]));
        foreach ($sheet->notes as $note) {
            $writer->addRow(new Row([new StringCell($note, new Style(fontItalic: true, fontColor: '595959'))]));
        }
        $writer->addRow(new Row([]));

        $header = new Style(fontBold: true, backgroundColor: 'E8DCCF', shouldWrapText: true);
        $writer->addRow(new Row(array_map(static fn (ReportColumn $column): Cell => new StringCell($column->label, $header), $sheet->columns)));

        foreach ($sheet->rows as $values) {
            $cells = [];
            foreach ($sheet->columns as $index => $column) {
                $cells[] = $this->cell($column->type, $values[$index] ?? null);
            }
            $writer->addRow(new Row($cells));
        }
    }

    private function cell(ColumnType $type, string|int|ReportMoney|null $value): Cell
    {
        return match (true) {
            $value === null || $value === '' => new EmptyCell(null),
            $value instanceof ReportMoney => new NumericCell((float) $value->decimal(), $this->moneyStyle($value->exponent)),
            is_int($value) => new NumericCell($value),
            $type === ColumnType::Day && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) === 1 => new DateTimeCell(new DateTimeImmutable($value, new DateTimeZone('UTC')), new Style(format: self::DAY)),
            $type === ColumnType::Month && preg_match('/^\d{4}-\d{2}\z/', $value) === 1 => new DateTimeCell(new DateTimeImmutable($value.'-01', new DateTimeZone('UTC')), new Style(format: self::MONTH)),
            // A figure that waits for a module, in an amount's column: said, never a number.
            $type === ColumnType::Money => new StringCell($value, new Style(fontItalic: true, fontColor: '815D2C')),
            default => new StringCell($value),
        };
    }

    private function moneyStyle(int $exponent): Style
    {
        return $this->money[$exponent] ??= new Style(format: $exponent > 0 ? '#,##0.'.str_repeat('0', $exponent) : '#,##0');
    }

    private static function width(ReportColumn $column): float
    {
        return match ($column->type) {
            ColumnType::Text => in_array($column->key, ['product', 'size', 'category', 'phrase', 'store'], true) ? 32.0 : 14.0,
            ColumnType::Money => 18.0,
            ColumnType::Day, ColumnType::Month => 12.0,
            ColumnType::Count => 12.0,
        };
    }
}
