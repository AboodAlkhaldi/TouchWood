<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PricingActions;

/**
 * **Why a row's button is drawn disabled** (pricing.md §4.3.3 P1), in the page's language, for the rows'
 * own marks: no price yet ("Add a price first."), not sold wholesale in the store, archived in the catalog,
 * and the provider setting the price.
 */
final readonly class PricingReasons
{
    public function __construct(
        public string $noPrice,
        public string $notSoldWholesale,
        public string $archived,
        public string $wiredPrice,
    ) {}
}
