<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListWholesale;

use Modules\Pricing\Application\Query\PriceViews\BandView;
use Modules\Pricing\Application\Query\PriceViews\RetailPriceView;
use Modules\Pricing\Application\Query\PriceViews\SizeView;

/**
 * One row of the Wholesale Prices screen (pricing.md §4.3.3 P4): the size; its retail price; its bands
 * ("From 10: 95.00 · From 50: 90.00"); the product's wholesale minimum and maximum in the store; and the
 * marks - **Kept, Not Sold Wholesale** (§1.4: kept unused), **No Retail Price** (the bands don't apply until
 * it has one), **Minimum Changed** (the minimum is no longer where the first band starts).
 */
final readonly class WholesaleRow
{
    /**
     * @param  list<BandView>  $bands
     */
    public function __construct(
        public SizeView $size,
        public ?RetailPriceView $retail,
        public array $bands,
        public ?int $minimum,
        public ?int $maximum,
        public bool $keptNotSoldWholesale,
        public bool $noRetailPrice,
        public bool $minimumChanged,
    ) {}
}
