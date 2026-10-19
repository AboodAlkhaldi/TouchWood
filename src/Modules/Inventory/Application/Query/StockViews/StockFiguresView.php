<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\StockViews;

/**
 * **A size's figures in a store, as every Inventory screen shows them** (inventory.md §4.3.1), each its
 * own column, never computed on the page:
 *
 * - **In Stock** - ours, or in a wired store the provider's last number - and a wired store's
 *   **returned extra** not yet in it (§1.9, provisional); **Held** by orders not yet shipped;
 *   **Available**, never below 0;
 * - **Threshold**: the one that applies, and the size's own (none: "10 (Default)");
 * - **the one badge** (Q17, Q18) - `SHORT` with how many pieces, `OUT_OF_STOCK`, `ORDERABLE`, `LOW` - or none;
 * - whether its stock limits ordering here, and a wired store's **Counts on Stock** switch;
 * - when the provider last **changed** its number (Q23; ISO 8601) - none in a store with no provider,
 *   and none in a wired one for a size with no `PROVIDER` line: the page says "From Provider" with no
 *   time, never "not sent" - the provider may have sent the same number, or 0 for a size with no row
 *   (coordinator's decision, 2026-10-19);
 * - how many orders hold it, for "2 orders hold 5 of these" under a box before saving (Q20).
 */
final readonly class StockFiguresView
{
    public function __construct(
        public int $inStock,
        public int $held,
        public int $available,
        public int $returnedExtra,
        public int $threshold,
        public ?int $ownThreshold,
        public ?string $badge,
        public int $shortBy,
        public bool $countsOnStock,
        public bool $stockDependent,
        public ?string $providerChangedAt,
        public int $holdingOrders,
    ) {}
}
