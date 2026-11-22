<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * One module's fact about the product cards a shop page shows (catalog.md amendment 32) — "you saved this"
 * — read on every request for the page's products at once, never kept with the page: it changes with who
 * is looking.
 */
interface ShopCardFact
{
    /**
     * @param  list<string>  $productIds  the page's cards, in order
     * @return array<string, array<string, mixed>> product id => plain data for that card; a product with nothing to say left out
     */
    public function read(StoreId $store, ?string $customerId, array $productIds): array;
}
