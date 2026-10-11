<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * What one store does with a product it has chosen (catalog.md §1.3, §4.4 S9 "Stores"): whether the
 * whole product is not available now, its quantities for each way of selling, the labels it carries
 * there, and each variant's row - on, retail, wholesale, not available now. A store that never chose
 * the product has no row.
 */
final readonly class StoreListingRow
{
    /**
     * @param  list<string>  $labelIds  in the labels' order
     * @param  array<string, array{on: bool, notAvailable: bool, retail: bool, wholesale: bool}>  $variants  variant id => its row there
     */
    public function __construct(
        public string $storeId,
        public bool $notAvailable,
        public int $retailMinimum,
        public ?int $retailMaximum,
        public ?int $wholesaleMinimum,
        public ?int $wholesaleMaximum,
        public array $labelIds,
        public array $variants,
    ) {}
}
