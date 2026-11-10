<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Sheets;

use DateInterval;
use DateTimeInterface;
use Modules\Content\Application\Redirects\SheetReader;
use Modules\Content\Domain\Exception\SheetRefused;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/** `SheetReader` over openspout (v5): the first sheet of an xlsx, or a csv in UTF-8 with commas. */
final class OpenSpoutSheetReader implements SheetReader
{
    public function rows(string $path, string $type, int $maxRows): array
    {
        // Empty rows kept, so each row's number is its row in the sheet; they are left out below.
        $reader = $type === self::XLSX
            ? new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true))
            : new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $rows = [];

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = array_values(array_map(self::text(...), $row->toArray()));

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    $rows[] = ['row' => $number, 'cells' => $cells];

                    if (count($rows) >= $maxRows) {
                        break;
                    }
                }

                // The first sheet only.
                break;
            }
        } catch (Throwable) {
            throw new SheetRefused(SheetRefused::TYPE);
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private static function text(mixed $cell): string
    {
        return match (true) {
            $cell instanceof DateTimeInterface => $cell->format('Y-m-d H:i'),
            $cell instanceof DateInterval => '',
            is_bool($cell) => $cell ? '1' : '0',
            is_scalar($cell) => trim((string) $cell),
            default => '',
        };
    }
}
