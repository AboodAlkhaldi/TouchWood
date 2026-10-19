<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSizePrices;

/**
 * **A size's prices in a store** - the Prices screen's drawer (pricing.md §4.3.3, Q3).
 */
final readonly class ViewSizePrices
{
    public function __construct(
        public string $storeId,
        public string $variantId,
    ) {}
}
