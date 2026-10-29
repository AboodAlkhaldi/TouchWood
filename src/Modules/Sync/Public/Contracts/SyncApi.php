<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Contracts;

use DateTimeImmutable;
use Modules\Sync\Public\Dto\SyncDayDto;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Sync (sync.md §2.2, amendment 1). Module to module, so no permission is
 * checked here - the calling use case checks its own (Ops' daily report: `ops.report.daily`). Refusals
 * arrive as `Shared\Domain\Error\DomainError` with a stable `type()` key.
 */
interface SyncApi
{
    /**
     * The reads finished and the problems opened, per store and per day, for Ops' daily report (ops.md
     * §1.11 item 6; sync.md amendment 1).
     *
     * Each day is the store's own calendar day in its own time zone, midnight to the next midnight (23 or
     * 25 hours where the clocks change); the day `$until` falls in is cut at `$until`, and a day that has
     * not begun by then is left out for that store. One answer per store and day - in the stores' order
     * given, a store named twice answered once, then by day - with zeros when nothing happened, a store
     * never wired or switched off included.
     *
     * A read counts on the day it finished: done, failed, or stopped by Sync itself (unwired, wired again,
     * superseded). A problem counts on the day its report entry opened. A day that began more than 90
     * days ago answers what Sync still keeps, marked not complete. A fixed number of queries whatever the
     * stores and days. Refused (`sync.invalid_sync_input`): a store that does not exist (`store`), a day
     * that is not `Y-m-d` or `$fromDay` after `$toDay` (`day`).
     *
     * @param  list<StoreId>  $stores
     * @param  string  $fromDay  `Y-m-d`, the first day, included
     * @param  string  $toDay  `Y-m-d`, the last day, included
     * @return list<SyncDayDto>
     */
    public function readsAndProblemsPerDay(array $stores, string $fromDay, string $toDay, DateTimeImmutable $until): array;
}
