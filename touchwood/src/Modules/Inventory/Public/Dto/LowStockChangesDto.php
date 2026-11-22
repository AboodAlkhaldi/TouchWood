<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Dto;

/**
 * **`InventoryApi::lowStockChanges`** (inventory.md §2.1, amendment 1): the sizes whose change of each kind
 * happened in the range - each list the sizes once, in id order; a size that changed several times may be
 * in more than one list.
 */
final readonly class LowStockChangesDto
{
    /**
     * @param  list<string>  $becameLow
     * @param  list<string>  $ranOut
     * @param  list<string>  $cameBack
     */
    public function __construct(
        public array $becameLow,
        public array $ranOut,
        public array $cameBack,
    ) {}
}
