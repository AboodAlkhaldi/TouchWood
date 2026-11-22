<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\CountSyncReport;

use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Shared\Application\Unauthorized;

/**
 * The count beside the Sync report's menu item (sync.md §1.6; owner, 2026-10-13 #3): the open entries,
 * one query - like Low Stock's. The menu entry itself comes with the report's screen.
 */
final readonly class CountSyncReportHandler
{
    public function __construct(
        private ReportAccess $access,
        private SyncLog $log,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput
     */
    public function handle(CountSyncReport $query): int
    {
        return $this->log->openCount($this->access->authorize(SyncPermissions::REPORT_VIEW, $query->storeId));
    }
}
