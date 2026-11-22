<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewProductPrices;

use Modules\Pricing\Application\Query\PriceViews\BandView;
use Modules\Pricing\Application\Query\PriceViews\RetailPriceView;
use Modules\Pricing\Application\Query\PriceViews\TodayPrice;

/**
 * One size's row in a store card (pricing.md §4.3.3, the product page): its retail price with source and
 * when, what one piece costs today and why, and its wholesale bands ("3 bands").
 */
final readonly class ProductSizePrices
{
    /**
     * @param  list<BandView>  $bands
     */
    public function __construct(
        public string $variantId,
        public ?RetailPriceView $retail,
        public ?TodayPrice $today,
        public array $bands,
    ) {}
}
