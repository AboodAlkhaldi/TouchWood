<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Catalog\Public\Dto\ProductDto;

/**
 * **A store's low sizes as the reader found them** (inventory.md §1.7): the sizes, the fewest left first
 * then by size id; their products as Catalog read them to leave the archived ones off - kept, so the
 * list orders by name without reading them again; and the store's wiring and default threshold, which
 * decided them.
 */
final readonly class LowRead
{
    /**
     * @param  list<LowSize>  $sizes
     * @param  array<string, ProductDto>  $products  by id
     * @param  array<string, string>  $productOf  each size's product id, by size id
     */
    public function __construct(
        public array $sizes,
        public array $products,
        public array $productOf,
        public bool $wired,
        public int $defaultThreshold,
    ) {}
}
