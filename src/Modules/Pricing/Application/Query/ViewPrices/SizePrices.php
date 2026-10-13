<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewPrices;

/**
 * A size's prices in a store: its retail price (minor units, or none - it needs one), the store's
 * currency, and every price it may be sold at.
 */
final readonly class SizePrices
{
    /**
     * @param  list<PriceLine>  $prices
     */
    public function __construct(
        public string $variantId,
        public ?int $retailMinor,
        public string $currencyCode,
        public array $prices,
    ) {}
}
