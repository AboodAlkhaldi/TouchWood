<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * One module's part of a file (catalog.md §2.3): the price and stock a products file or a store file
 * gives for a product in a store, as the file wrote them. **Declared in step 6; its first
 * implementation comes with stage 5**, which may refine it before anything calls it.
 */
interface ImportSection
{
    /**
     * Lines for the file's page about one product in one store — a price to be set, a stock to be
     * written, or why one will not be (a store wired to a provider ignores them, handoff §9.1).
     *
     * @return list<string>
     */
    public function lines(StoreId $store, ?string $price, ?int $stock): array;

    /**
     * The product was accepted, or its item switched on, in the store: keep its part, for these variants.
     *
     * @param  list<string>  $variantIds
     */
    public function accepted(StoreId $store, array $variantIds, ?string $price, ?int $stock): void;
}
