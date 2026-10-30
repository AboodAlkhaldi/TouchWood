<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

/**
 * One sheet of a report: its name, its columns, the notes that say what its figures are, and its rows.
 *
 * The rows are read as they are written - a generator walking the owning module's paged read - so a
 * report of any length never sits in memory whole. They can be walked once.
 */
final readonly class ReportSheet
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<string>  $notes
     * @param  iterable<list<string|int|ReportMoney|null>>  $rows  one value per column, as its type says
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $notes,
        public iterable $rows,
    ) {}
}
