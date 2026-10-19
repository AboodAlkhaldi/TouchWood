<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewProductPrices;

/**
 * **A product's prices in every store the reader prices in** (pricing.md §4.3.3, the product page's
 * Pricing section): its sizes as the page lists them, in these stores - one read for every store card.
 */
final readonly class ViewProductPrices
{
    /**
     * @param  list<string>  $variantIds  the product's sizes not archived, in its order
     * @param  list<string>  $storeIds  the page's stores
     */
    public function __construct(
        public string $productId,
        public array $variantIds,
        public array $storeIds,
    ) {}
}
