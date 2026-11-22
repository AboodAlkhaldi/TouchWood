<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ViewProductStock;

/**
 * **Inventory's part of one store card** on a product's Stores tab (inventory.md §4.3.3, the product
 * page's Inventory section): whether the store is wired to a provider - its stock then the provider's,
 * read-only - or off (a Super Admin's, marked **Off**), the product's **Last Pieces** switch with no
 * provider (none in a wired store), and each size's stock there.
 */
final readonly class ProductStoreStock
{
    /**
     * @param  list<ProductSizeStock>  $sizes  in the product's order
     * @param  int  $defaultThreshold  the store's own, for Set Threshold's "Empty uses the store's default" (stage 5's screens step 5)
     */
    public function __construct(
        public string $storeId,
        public bool $wired,
        public bool $off,
        public ?bool $lastPieces,
        public array $sizes,
        public int $defaultThreshold = 0,
    ) {}
}
