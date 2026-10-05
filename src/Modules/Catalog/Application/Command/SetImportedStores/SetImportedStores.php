<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedStores;

/**
 * The stores the products of an import are switched on in when accepted, with a price and stock
 * there (catalog.md §1.12, amendment 7(c)) — shown, and kept from stage 5.
 */
final readonly class SetImportedStores
{
    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for all
     * @param  array<array-key, mixed>  $storeCodes  as the panel shows them (sa, eg)
     * @param  mixed  $price  a number of at least 0, in each store's currency, or null
     * @param  mixed  $stock  a whole number of at least 0, or null
     * @param  string  $mode  REPLACE the price and stock the products have there, or FILL_EMPTY: only where they have none
     */
    public function __construct(
        public string $importId,
        public ?array $productIds,
        public array $storeCodes,
        public mixed $price,
        public mixed $stock,
        public string $mode,
    ) {}
}
