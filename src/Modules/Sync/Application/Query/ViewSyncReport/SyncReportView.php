<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncReport;

use Modules\Sync\Application\Report\ReportEntryView;

/**
 * A page of a store's open Sync report entries, the latest seen first, and how many are open.
 */
final readonly class SyncReportView
{
    /**
     * @param  list<ReportEntryView>  $entries
     */
    public function __construct(
        public array $entries,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
