<?php

declare(strict_types=1);

namespace Modules\Content\Application\Redirects;

use Modules\Content\Domain\Exception\SheetRefused;

/**
 * Reads a redirect sheet's rows (content.md §1.15: "xlsx or csv (`openspout`, installed)"): the first sheet's cells
 * as text, row by row, the empty rows left out. It stops after `$maxRows` rows, so a sheet over the limit is known
 * without reading it whole.
 */
interface SheetReader
{
    public const string XLSX = 'xlsx';

    public const string CSV = 'csv';

    /**
     * @param  'xlsx'|'csv'  $type
     * @return list<array{row: int, cells: list<string>}> each row's number in the sheet (1 the first) and its cells
     *
     * @throws SheetRefused a file that is not one of its type (`type`)
     */
    public function rows(string $path, string $type, int $maxRows): array;
}
