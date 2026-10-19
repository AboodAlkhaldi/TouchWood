<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\HoldsOnSizes;

/**
 * **What orders hold of these sizes in a store, before saving** (inventory.md §4.3.1, Q20): for "2
 * orders hold 5 of these. This count leaves them short." under a box or in a dialog.
 */
final readonly class HoldsOnSizes
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $storeId,
        public array $variantIds,
    ) {}
}
