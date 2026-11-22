<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Closure;

/**
 * One sheet of a report: its name, its columns, the notes that say what its figures are, and its rows.
 *
 * The rows are read as they are written - a generator walking the owning module's paged read - so a
 * report of any length never sits in memory whole. They can be walked once.
 *
 * A total row is a ReportTotal, after the rows it adds up, summed as they are read: what the file writes. A
 * preview stops at the rows it shows, before them, so a sheet that has totals also says how to read them without
 * its rows (`totals`), with reads that sum and that agree with its rows to the unit.
 */
final readonly class ReportSheet
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<string>  $notes
     * @param  iterable<list<string|int|ReportMoney|null>|ReportTotal>  $rows  one value per column, as its type says
     * @param  (Closure(): list<list<string|int|ReportMoney|null>>)|null  $totals
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $notes,
        public iterable $rows,
        public ?Closure $totals = null,
    ) {}
}
