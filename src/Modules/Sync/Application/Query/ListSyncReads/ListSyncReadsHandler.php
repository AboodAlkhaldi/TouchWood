<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ListSyncReads;

use Modules\Sync\Application\Report\Cursor;
use Modules\Sync\Application\Report\ReadViews;
use Modules\Sync\Application\Report\ReportAccess;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Shared\Application\Unauthorized;

/**
 * **The page of a store's reads** (owner, 2026-10-17, sync.md §9.3 #19; §4.1 Y1 Reads), for staff with
 * View the Sync Report there: each read, the latest asked first, 50 at a time - kept 90 days - who asked
 * by name (Q36), a failed read's counts from what it wrote (Q30). A fixed few queries a page.
 */
final readonly class ListSyncReadsHandler
{
    public const int PER_PAGE = 50;

    public function __construct(
        private ReportAccess $access,
        private SyncLog $log,
        private ReadViews $views,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput
     */
    public function handle(ListSyncReads $query): ReadsView
    {
        $store = $this->access->authorize(SyncPermissions::REPORT_VIEW, $query->storeId);
        $rows = $this->log->reads($store, Cursor::read($query->after, 'read'), self::PER_PAGE + 1);
        $more = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new ReadsView(
            $this->views->of($rows),
            $more && $last !== null ? Cursor::of($last['queuedAt'], $last['id']) : null,
            $this->log->readsCount($store),
        );
    }
}
