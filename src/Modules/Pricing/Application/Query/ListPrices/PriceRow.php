<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListPrices;

use Modules\Pricing\Application\Query\PriceViews\BandView;
use Modules\Pricing\Application\Query\PriceViews\RetailPriceView;
use Modules\Pricing\Application\Query\PriceViews\SizeView;
use Modules\Pricing\Application\Query\PriceViews\TodayPrice;

/**
 * One row of the Prices screen (pricing.md §4.3.3 P1): the size; its retail price with its source and
 * when (none: "No price"); what one piece costs today and why (none without a price); **Wholesale Only**
 * - the store sells it in quantity only; how many wholesale bands it has, and whether they are **kept,
 * not sold wholesale** (§1.4). The size carries **Archived** and **Not Switched On**. Its first band, for
 * the warning a box says as a lower retail price is typed (Q8), and whether the store sells it wholesale -
 * Edit Wholesale Prices' reason when it does not (stage 5's screens step 3).
 */
final readonly class PriceRow
{
    public function __construct(
        public SizeView $size,
        public ?RetailPriceView $retail,
        public ?TodayPrice $today,
        public bool $wholesaleOnly,
        public int $bands,
        public bool $keptNotSoldWholesale,
        public ?BandView $firstBand = null,
        public bool $soldWholesale = false,
    ) {}
}
