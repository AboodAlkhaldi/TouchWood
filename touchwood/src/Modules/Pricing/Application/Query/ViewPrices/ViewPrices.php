<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewPrices;

/**
 * A size's prices in a store (pricing.md §3): what its price screen shows.
 */
final readonly class ViewPrices
{
    public function __construct(
        public string $storeId,
        public string $variantId,
    ) {}
}
