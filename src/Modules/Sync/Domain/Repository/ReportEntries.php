<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

use Shared\Domain\ValueObject\StoreId;

/**
 * The Sync report's entries (sync.md §1.6, §5 `sync.report_entries`).
 */
interface ReportEntries
{
    /** Every open entry of the store closed, saying by what (sync.md §1.8: unwiring). */
    public function closeAll(StoreId $store, string $closedBy): int;
}
