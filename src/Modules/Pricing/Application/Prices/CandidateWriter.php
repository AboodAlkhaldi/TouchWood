<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Catalog\Public\Dto\ListingPrice;
use Modules\Catalog\Public\Enums\SaleMode;
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
use Modules\Pricing\Public\Events\DiscountReachChecked;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * Writes sizes' candidates again from what they are made of (pricing.md §1.6 "materialized"), inside
 * the change's transaction, then tells Catalog the price a card shows (§1.10): what one piece costs
 * now on a retail line, and the retail price before it only while the price now is lower — and the
 * least one piece costs now on a wholesale line (catalog.md amendment 35, the card's "Wholesale from").
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
        private CeilingCheck $ceilingCheck,
        private Dispatcher $events,
    ) {}

    /**
     * @param  list<string>  $variantIds
     * @param  bool  $discountsChanged  a category discount was added, changed, ended or removed: a wired
     *                                  store is told what its discounts reach even when none is left
     */
    public function refresh(StoreId $store, array $variantIds, bool $discountsChanged = false): void
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        // The store's discounts not ended, each with the products it reaches now - read once.
        $discounts = $this->discounts->notEnded($store, $now);
        $reaches = [];
        // In a wired store a fixed amount never goes deeper than the ceiling: where the provider's price
        // would let it, it skips the size (sync.md §9.3 #17). Elsewhere such a price is refused (§1.12).
        // Asked only when a discount is there or changed: any other price costs no more.
        $wired = ($discounts !== [] || $discountsChanged) && $this->ceilingCheck->skipsPastCeiling($store);
        $ceiling = $discounts !== [] && $wired ? $this->ceilingCheck->ceiling($store) * 100 : PHP_INT_MAX;
        $skipped = [];

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
                    // fixed amount that would take it to 0 or below - or, in a wired store, past the ceiling
                    // - skips it.
                    foreach ($discounts as $discount) {
                        $amount = isset($reaches[$discount->id][$productOf[$variantId] ?? '']) ? $discount->priceOn($retail[$variantId]) : null;

                        if ($amount === null) {
                            continue;
                        }

                        if ($discount->percentBasisPoints === null && $discount->depthOn($retail[$variantId]) > $ceiling) {
                            $skipped[] = ['discountId' => $discount->id, 'variantId' => $variantId];

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
                    self::wholesaleFrom($candidates, $now),
                );
            }

            $this->candidates->replace($store, $written);
            $this->listing->prices($store, $prices);
        }

        // Sync closes a skipped discount's entry the moment it reaches the size again (sync.md §9.3 #28).
        if ($wired && $variantIds !== []) {
            $this->events->dispatch(new DiscountReachChecked((string) Str::uuid(), $store->value, $variantIds, $skipped, CarbonImmutable::now()));
        }
    }

    /**
     * The least one piece costs now on a wholesale line, at any quantity: the line resolved from one piece
     * and from each band's start — the price only changes there — the cheapest kept. None: not priced.
     *
     * @param  list<Candidate>  $candidates
     */
    private static function wholesaleFrom(array $candidates, DateTimeImmutable $now): ?Money
    {
        $least = null;
        $starts = [1];

        foreach ($candidates as $candidate) {
            if ($candidate->kind === PriceKind::Quantity) {
                $starts[] = $candidate->fromQuantity;
            }
        }

        foreach (array_unique($starts) as $quantity) {
            $unit = PriceResolution::resolve($candidates, SaleMode::Wholesale, $quantity, $now)?->unit;

            if ($unit !== null && ($least === null || $unit->compareTo($least) < 0)) {
                $least = $unit;
            }
        }

        return $least;
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
