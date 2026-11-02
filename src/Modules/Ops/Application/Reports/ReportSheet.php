<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

/**
 * One sheet of a report: its name, its columns, the notes that say what its figures are, and its rows.
 *
 * The rows are read as they are written - a generator walking the owning module's paged read - so a
 * report of any length never sits in memory whole. They can be walked once.
 *
 * A total row is a ReportTotal, after the rows it adds up. `hasTotals` says the sheet may write one, so a
 * preview walks it to its end for them, however many rows it does not keep; one without reads no further than
 * the rows it shows.
 */
final readonly class ReportSheet
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<string>  $notes
     * @param  iterable<list<string|int|ReportMoney|null>|ReportTotal>  $rows  one value per column, as its type says
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $notes,
        public iterable $rows,
        public bool $hasTotals = false,
    ) {}
}
