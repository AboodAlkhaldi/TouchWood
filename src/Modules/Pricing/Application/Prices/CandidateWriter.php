<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Catalog\Public\Dto\ListingPrice;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * Writes sizes' candidates again from what they are made of (pricing.md §1.6 "materialized"), inside
 * the change's transaction, then tells Catalog the price a card shows (§1.10): what one piece costs
 * now on a retail line, and the retail price before it only while the price now is lower.
 *
 * Step 3a: the retail price, a candidate on both lines. Sales, bands and category discounts join their
 * own candidates here with their steps.
 */
final readonly class CandidateWriter
{
    public function __construct(
        private RetailPrices $retailPrices,
        private PriceCandidates $candidates,
        private ListingFacts $listing,
    ) {}

    /**
     * @param  list<string>  $variantIds
     */
    public function refresh(StoreId $store, array $variantIds): void
    {
        if ($variantIds === []) {
            return;
        }

        $retail = $this->retailPrices->forVariants($store, $variantIds);
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $prices = [];

        foreach ($variantIds as $variantId) {
            $candidates = [];

            if (isset($retail[$variantId])) {
                foreach ([SaleMode::Retail, SaleMode::Wholesale] as $mode) {
                    $candidates[] = new Candidate($mode, 1, $retail[$variantId], PriceKind::Base);
                }
            }

            $this->candidates->replace($store, $variantId, $candidates);

            $price = PriceResolution::resolve($candidates, SaleMode::Retail, 1, $now);
            $prices[$variantId] = $price === null ? null : new ListingPrice(
                $price->unit,
                $price->unit->compareTo($price->listUnit) < 0 ? $price->listUnit : null,
            );
        }

        $this->listing->prices($store, $prices);
    }
}
