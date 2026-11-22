<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Command\RemoveRetailPrice;

/**
 * Remove the retail price of one size or several in a store (pricing.md §1.2, §3): each goes off sale
 * there and back on the Needs a Price list.
 */
final readonly class RemoveRetailPrice
{
    /**
     * @param  list<string>  $variantIds
     */
    public function __construct(
        public string $storeId,
        public array $variantIds,
    ) {}
}
