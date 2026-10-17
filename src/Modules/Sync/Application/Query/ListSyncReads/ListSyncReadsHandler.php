<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Shared\Application\Unauthorized;

/**
 * **The page of a store's reads** (owner, 2026-10-17, sync.md §9.3 #19), for staff with View the Sync
 * Report there: each read, the latest asked first - kept 90 days.
 */
final readonly class ListSyncReadsHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private ReportAccess $access,
        private SyncLog $log,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput
     */
    public function handle(ListSyncReads $query): ReadsView
    {
        $store = $this->access->authorize(SyncPermissions::REPORT_VIEW, $query->storeId);
        $page = max(1, $query->page);
        [$rows, $total] = $this->log->reads($store, $page, self::PER_PAGE);

        return new ReadsView(array_map(ReadView::of(...), $rows), $total, $page, self::PER_PAGE);
    }
}
