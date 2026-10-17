<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Catalog\Public\Dto\ListingPrice;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Modules\Pricing\Domain\Service\PercentOff;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Domain\ValueObject\Sale;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * Writes sizes' candidates again from what they are made of (pricing.md §1.6 "materialized"), inside
 * the change's transaction, then tells Catalog the price a card shows (§1.10): what one piece costs
 * now on a retail line, and the retail price before it only while the price now is lower.
 *
 * In chunks, so the repair of a whole store stays under PostgreSQL's 65,535 parameters a statement
 * (as Catalog's listing rows): a few reads and writes a chunk, however many sizes.
 *
 * The retail price, a candidate on both lines; every sale not ended on the size, on both lines too -
 * a percentage worked out on the retail price, half up to the coin, a sale whose percentage would
 * leave 0 at today's retail price left out; the wholesale bands, on wholesale lines only; every
 * category discount not ended reaching the size's product, on its lines.
 */
final readonly class CandidateWriter
{
    /** Sizes a chunk. */
    public const int CHUNK = 500;

    public function __construct(
        private RetailPrices $retailPrices,
        private PriceCandidates $candidates,
        private ListingFacts $listing,
        private Sales $sales,
        private WholesaleBands $bands,
        private CategoryDiscounts $discounts,
        private DiscountCoverage $coverage,
        private CatalogApi $catalog,
        private PlatformApi $platform,
    ) {}

    /**
     * @param  list<string>  $variantIds
     */
    public function refresh(StoreId $store, array $variantIds): void
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        // The store's discounts not ended, each with the products it reaches now - read once.
        $discounts = $this->discounts->notEnded($store, $now);
        $reaches = [];
        // A fixed amount never goes deeper than the ceiling: where a price would let it, it skips the
        // size (sync.md §9.3 #17) - reached in a wired store, whose prices are the provider's;
        // elsewhere such a price is refused (§1.12).
        $ceiling = $discounts === [] ? 0 : (int) $this->platform->setting(PricingSettings::MAX_DISCOUNT_PERCENT, $store)->value * 100;

        foreach ($discounts as $discount) {
            $reaches[$discount->id] = $this->coverage->products($discount);
        }

        foreach (array_chunk($variantIds, self::CHUNK) as $chunk) {
            $retail = $this->retailPrices->forVariants($store, $chunk);
            $productOf = $discounts === [] ? [] : array_map(static fn ($variant): string => $variant->productId, $this->catalog->variants($chunk));
            $salesOn = [];

            foreach ($this->sales->notEndedOn($store, $chunk, $now) as $sale) {
                foreach ($sale->variantIds as $variantId) {
                    $salesOn[$variantId][] = $sale;
                }
            }

            $bands = $this->bands->forVariants($store, $chunk);
            $written = [];
            $prices = [];

            foreach ($chunk as $variantId) {
                $candidates = [];

                if (isset($retail[$variantId])) {
                    // Wholesale lines only (§1.4): each band from its quantity.
                    foreach ($bands[$variantId] ?? [] as $band) {
                        $candidates[] = new Candidate(SaleMode::Wholesale, $band->fromQuantity, $band->amount, PriceKind::Quantity);
                    }

                    foreach ([SaleMode::Retail, SaleMode::Wholesale] as $mode) {
                        $candidates[] = new Candidate($mode, 1, $retail[$variantId], PriceKind::Base);

                        foreach ($salesOn[$variantId] ?? [] as $sale) {
                            $amount = self::saleAmount($sale, $retail[$variantId]);

                            if ($amount !== null) {
                                $candidates[] = new Candidate($mode, 1, $amount, PriceKind::Sale, $sale->alwaysWins, $sale->startsAt, $sale->endsAt, $sale->id);
                            }
                        }
                    }

                    // Every discount reaching the size's product, on the lines it applies to (§1.5); a
                    // fixed amount that would take it to 0 or below, or past the ceiling, skips it.
                    foreach ($discounts as $discount) {
                        $amount = isset($reaches[$discount->id][$productOf[$variantId] ?? '']) ? $discount->priceOn($retail[$variantId]) : null;

                        if ($amount === null || ($discount->percentBasisPoints === null && $discount->depthOn($retail[$variantId]) > $ceiling)) {
                            continue;
                        }

                        foreach ([SaleMode::Retail, SaleMode::Wholesale] as $mode) {
                            if ($discount->lines->covers($mode)) {
                                $candidates[] = new Candidate($mode, 1, $amount, PriceKind::Category, $discount->alwaysWins, $discount->startsAt, $discount->endsAt, $discount->id);
                            }
                        }
                    }
                }

                $written[$variantId] = $candidates;
                $price = PriceResolution::resolve($candidates, SaleMode::Retail, 1, $now);
                $prices[$variantId] = $price === null ? null : new ListingPrice(
                    $price->unit,
                    $price->unit->compareTo($price->listUnit) < 0 ? $price->listUnit : null,
                );
            }

            $this->candidates->replace($store, $written);
            $this->listing->prices($store, $prices);
        }
    }

    /** The sale's price on a size: its own, or its percentage of the retail price; none if that would be 0. */
    private static function saleAmount(Sale $sale, Money $retail): ?Money
    {
        if ($sale->percentBasisPoints === null) {
            return $sale->price;
        }

        try {
            return PercentOff::price($retail, $sale->percentBasisPoints);
        } catch (PriceNotPositive) {
            return null;
        }
    }
}
