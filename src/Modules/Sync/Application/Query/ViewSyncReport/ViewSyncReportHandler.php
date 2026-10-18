<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncReport;

use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\ReportEntryView;
use Modules\Sync\Application\Report\SizeNames;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Application\Unauthorized;

/**
 * **The Sync report** (sync.md §1.6), for staff with View the Sync Report in that store: the open entries,
 * the latest seen first, each naming its size's product - to open and change it there - or the code
 * the provider sent. A few queries a page, however many entries.
 */
final readonly class ViewSyncReportHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private ReportAccess $access,
        private SyncLog $log,
        private SizeNames $names,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput
     */
    public function handle(ViewSyncReport $query): SyncReportView
    {
        $store = $this->access->authorize(SyncPermissions::REPORT_VIEW, $query->storeId);

        if ($query->kind !== null && ReportKind::tryFrom($query->kind) === null) {
            throw new InvalidSyncInput('kind');
        }

        $page = max(1, $query->page);
        [$rows, $total] = $this->log->openEntries($store, $query->kind, $page, self::PER_PAGE);
        $names = $this->names->of(array_column($rows, 'variantId'));

        return new SyncReportView(array_map(static fn (array $row): ReportEntryView => ReportEntryView::of($row, $names), $rows), $total, $page, self::PER_PAGE);
    }
}
