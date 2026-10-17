<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * The Sync report's entries (sync.md §1.6, §5 `sync.report_entries`): one open per store, kind, code and
 * size - the same thing again updates when it was last seen and what it says.
 */
interface ReportEntries
{
    /**
     * Entries of a kind opened, or the open ones seen again - one statement for a batch (handoff §12.2).
     *
     * @param  list<array{code: string|null, variantId: string|null, saleId: string|null, detail: array<string, mixed>}>  $entries
     */
    public function open(StoreId $store, ReportKind $kind, array $entries): void;

    /**
     * The open entries of a kind about these codes or sizes closed: what they said is right again.
     *
     * @param  list<string>  $codes
     * @param  list<string>  $variantIds
     */
    public function close(StoreId $store, ReportKind $kind, array $codes, array $variantIds, string $closedBy): int;

    /** The store's open entries of a kind closed - all of them (a failed read, once a read succeeds). */
    public function closeKind(StoreId $store, ReportKind $kind, string $closedBy): int;

    /** Every open entry of the store closed, saying by what (sync.md §1.8: unwiring). */
    public function closeAll(StoreId $store, string $closedBy): int;
}
