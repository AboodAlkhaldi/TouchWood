<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\SetStockDependent;

/**
 * The stock-dependent switch, on or off, for the sizes picked - all of a product's, some, or one
 * (inventory.md §1.2, §3, §4.2) - in a wired store.
 */
final readonly class SetStockDependent
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $storeId,
        public array $variantIds,
        public bool $on,
    ) {}
}
