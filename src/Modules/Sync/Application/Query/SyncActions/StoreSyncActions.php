<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\SyncActions;

/**
 * **What Sync's screens may offer in one store** (sync.md §4.1.3 `SyncActionsForReader`; pricing.md
 * §4.3.1 "Who may do what"): whether the reader may see its report, run its reads (Sync Now, Retry Read,
 * Dismiss Entry), wire it (a Super Admin, Y3); whether it is wired and whether it is off (a Super Admin's,
 * marked Off); whether a Sync Now read already waits there, and so why Sync Now is drawn disabled (Q26);
 * whether a link to a product's page (`catalog.product.view`) or to an offer or a discount (any of
 * Pricing's three jobs) may be drawn. Offering is never allowing: every handler asks again.
 */
final readonly class StoreSyncActions
{
    public function __construct(
        public string $storeId,
        public bool $mayView,
        public bool $mayRun,
        public bool $mayWire,
        public bool $mayViewProducts,
        public bool $mayViewOffers,
        public bool $wired,
        public bool $off,
        public bool $nowWaiting,
        public ?string $syncNowRefusal,
    ) {}
}
