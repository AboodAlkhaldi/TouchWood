<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

/**
 * Sync's log counted over day windows (sync.md amendment 1): each window a store's day, from and to as
 * moments, the end excluded. One query for the reads and one for the entries, however many windows.
 */
interface SyncDays
{
    /**
     * The reads finished (`DONE` or `FAILED`) in each window, grouped by window, read kind and outcome:
     * `DONE`, `STOPPED` (failed with one of `$stoppedCodes`) or `FAILED`. A done read's prices, stock and
     * skipped are its own counts; any other is counted from what it wrote (sync.md Q30).
     *
     * @param  list<array{storeId: string, day: string, from: string, to: string}>  $windows  moments in ISO 8601
     * @param  list<string>  $stoppedCodes
     * @return list<array{storeId: string, day: string, kind: string, outcome: string, reads: int, prices: int, stock: int, skipped: int}>
     */
    public function reads(array $windows, array $stoppedCodes): array;

    /**
     * The report entries opened in each window, grouped by window and kind.
     *
     * @param  list<array{storeId: string, day: string, from: string, to: string}>  $windows  moments in ISO 8601
     * @return list<array{storeId: string, day: string, kind: string, opened: int}>
     */
    public function problemsOpened(array $windows): array;
}
