<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Modules\Platform\Public\Contracts\MenuCount;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Sync\Application\SyncPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The count beside the Sync Report's menu entry** (sync.md §1.6, §4.1.2 `SyncReportCount`; owner,
 * 2026-10-13 #3): the open entries over every store the reader views the report in - an off one only for
 * whoever may switch stores, as the store filter offers it (Q1) - in one query. Asked only for someone
 * the entry is offered to.
 */
final readonly class SyncReportCount implements MenuCount
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private SyncLog $log,
    ) {}

    public function count(): int
    {
        $stores = $this->authorizer->storesWith(SyncPermissions::REPORT_VIEW);
        $mayOff = $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];

        // Every store, the off ones too: no store to leave out, and none named.
        if ($stores === null && $mayOff) {
            return $this->log->openCountIn(null);
        }

        $held = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);
        $shown = [];

        foreach ($this->platform->allStores() as $store) {
            if (($held === null || in_array(strtolower($store->id), $held, true)) && ($store->isActive || $mayOff)) {
                $shown[] = $store->storeId();
            }
        }

        return $this->log->openCountIn($shown);
    }
}
