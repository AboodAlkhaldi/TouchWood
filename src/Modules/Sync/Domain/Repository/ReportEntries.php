<?php

declare(strict_types=1);

namespace Modules\Sync\Domain\Repository;

use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * The Sync report's entries (sync.md §1.6, §5 `sync.report_entries`): one open per store, kind, code and
 * size - the same thing again updates when it was last seen.
 */
interface ReportEntries
{
    /**
     * An entry opened, or the open one seen again with what it says now.
     *
     * @param  array<string, mixed>  $detail
     */
    public function open(StoreId $store, ReportKind $kind, ?string $code, ?string $variantId, ?string $saleId, array $detail): void;

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
