<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\SetImportedPrices;

/**
 * A price and stock in one store for the products of an import switched on there (catalog.md §1.12,
 * amendment 8(b)) — shown, and kept from stage 5.
 */
final readonly class SetImportedPrices
{
    /**
     * @param  array<array-key, mixed>|null  $productIds  the import's products chosen on the page, or null for all
     * @param  mixed  $price  a number of at least 0, in the store's currency, or null to leave it
     * @param  mixed  $stock  a whole number of at least 0, or null to leave it
     * @param  string  $mode  REPLACE what the products have there, or FILL_EMPTY: only where they have none
     */
    public function __construct(
        public string $importId,
        public ?array $productIds,
        public string $storeCode,
        public mixed $price,
        public mixed $stock,
        public string $mode,
    ) {}
}
