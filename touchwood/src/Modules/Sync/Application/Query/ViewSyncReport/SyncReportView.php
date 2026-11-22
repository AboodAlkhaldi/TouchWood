<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncReport;

use Modules\Sync\Application\Report\ReportEntryView;

/**
 * A page of a store's open Sync report entries, the latest seen first: where the next page starts (null
 * on the last), how many the kind filter shows ("Showing 50 of 312"), how many are open in all (the
 * Problems tab's count), and how many of each kind - the eight in the spec's order, none left out - for
 * the kind filter.
 */
final readonly class SyncReportView
{
    /**
     * @param  list<ReportEntryView>  $entries
     * @param  array<string, int>  $byKind
     */
    public function __construct(
        public array $entries,
        public ?string $next,
        public int $total,
        public int $allOpen,
        public array $byKind,
    ) {}
}
