<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Prices\AmountInput;
use Modules\Pricing\Application\Prices\CeilingCheck;
use Modules\Pricing\Application\Prices\OfferNames;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReachViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\SaleInput;
use Modules\Pricing\Application\Prices\SaleSizes;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\PriceTooPrecise;
use Modules\Pricing\Domain\Exception\SaleNotFound;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\Service\PercentOff;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Domain\ValueObject\Sale;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **The note before saving** (`OverlapsFor`, pricing.md §1.3, §3, §4.3.3 P2), for whoever holds any of
 * Pricing's three jobs in that store (owner, 2026-10-08). The sizes are read as adding the sale reads them
 * (`SaleSizes`: ready and switched on, Q13; "All Sizes" the priced ones, Q39) - a sale being changed by its
 * own product and sizes, as they are - its start now when empty or past; every sale not ended on those sizes whose dates meet it is named, the sale itself left out when it
 * is being changed - and every category discount reaching the product in those dates.
 *
 * With its price or percentage typed (Q11), each size says the price it would get, whether that wins -
 * today, or at its start when it is scheduled - from the size's candidates with this sale among them
 * (§1.6), and how deep it goes against the store's ceiling: a warning, the server refusing on Save. A
 * read: no lock, nothing written.
 */
final readonly class OverlapsForHandler
{
    /** The sale being judged, among the size's candidates. */
    private const string ITSELF = 'note';

    public function __construct(
        private ReadAccess $access,
        private SaleSizes $sizes,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private CatalogApi $catalog,
        private PriceCandidates $candidates,
        private RetailPrices $retailPrices,
        private CeilingCheck $ceiling,
        private PriceViews $views,
        private OfferNames $names,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|NoRetailPrice|PriceTooPrecise|PriceNotPositive|SaleNotFound
     */
    public function handle(OverlapsFor $query): OverlapNote
    {
        $store = $this->access->authorize($query->storeId);
        $storeId = $store->storeId();
        // Ids are kept in lower case, as the sale's own handlers read them.
        $itself = $query->saleId === null ? null : strtolower(trim($query->saleId));

        if ($itself === null) {
            [$productId, $variantIds] = $this->sizes->of($storeId, $query->productId, $query->variantIds);
        } else {
            // A sale being changed keeps its product and sizes (§1.3): they are its own, never checked again -
            // its product made a draft since, or a size switched off, still has its note (Q13, Q39 are adding's).
            $sale = $this->sales->find($itself);

            if ($sale === null || $sale->storeId !== $store->id) {
                throw new SaleNotFound;
            }

            [$productId, $variantIds] = [$sale->productId, $sale->variantIds];
        }

        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $startsAt = SaleInput::start($query->startsAt, $now);
        $endsAt = SaleInput::end($query->endsAt);
        $variants = $this->catalog->variants($variantIds);
        $overlapping = [];

        foreach ($this->sales->notEndedOn($storeId, $variantIds, $now) as $sale) {
            if ($sale->id !== $itself && $sale->overlaps($startsAt, $endsAt)) {
                $overlapping[] = $sale;
            }
        }

        $saleNames = $this->names->sales($overlapping, $variants);
        $notEnded = $this->discounts->notEnded($storeId, $now);
        $categoryIds = $notEnded === [] ? [] : ($this->catalog->lineFacts([$variantIds[0]])[$variantIds[0]]->categoryIds ?? []);
        $discounts = array_values(array_filter(
            ReachViews::reaching($notEnded, $productId, $categoryIds),
            static fn ($discount): bool => $discount->overlaps($startsAt, $endsAt),
        ));
        $discountNames = $this->names->discounts($discounts);

        return new OverlapNote(
            $productId,
            $variantIds,
            array_map(static fn (Sale $sale): OverlappingSale => new OverlappingSale(
                $sale->id,
                $sale->percentBasisPoints,
                $sale->price?->minorUnits,
                $sale->price?->currencyCode,
                $sale->startsAt->format(DateTimeInterface::ATOM),
                $sale->endsAt?->format(DateTimeInterface::ATOM),
                $sale->alwaysWins,
                array_values(array_intersect($sale->variantIds, $variantIds)),
                $saleNames[$sale->id] ?? null,
            ), $overlapping),
            array_map(static fn ($discount): OverlappingDiscount => new OverlappingDiscount(
                $discount->id,
                $discount->categoryId,
                $discount->percentBasisPoints,
                $discount->amount?->minorUnits,
                $discount->amount?->currencyCode,
                $discount->lines->value,
                $discount->startsAt->format(DateTimeInterface::ATOM),
                $discount->endsAt?->format(DateTimeInterface::ATOM),
                $discount->alwaysWins,
                $discountNames[$discount->id] ?? null,
            ), $discounts),
            $this->sizesNote($query, $store, $productId, $variantIds, $variants, $startsAt, $endsAt, $itself, $now),
            $startsAt > $now,
            $query->percent === null && $query->price === null ? null : $this->ceiling->ceiling($storeId),
        );
    }

    /**
     * Each size with the sale as typed (Q11): none until a price or a percentage is.
     *
     * @param  list<string>  $variantIds
     * @param  array<string, VariantDto>  $variants
     * @return list<OverlapSize>
     */
    private function sizesNote(OverlapsFor $query, StoreDto $store, string $productId, array $variantIds, array $variants, DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt, ?string $itself, DateTimeImmutable $now): array
    {
        if (($query->percent === null) === ($query->price === null)) {
            return [];
        }

        $storeId = $store->storeId();
        $sale = new Sale(
            self::ITSELF,
            $store->id,
            $productId,
            $query->percent === null ? null : SaleInput::percent($query->percent),
            $query->price === null ? null : AmountInput::of($query->price, $store),
            $startsAt,
            $endsAt,
            $query->alwaysWins,
            $variantIds,
        );
        $ceiling = $this->ceiling->ceiling($storeId) * 100;
        $retail = $this->retailPrices->forVariants($storeId, $variantIds);
        $candidates = $this->candidates->forVariants($storeId, $variantIds);
        // Judged when it starts: today for one starting now, its start for one scheduled.
        $at = max($startsAt, $now);
        $sizes = [];

        foreach ($variantIds as $variantId) {
            $price = $retail[$variantId] ?? null;
            $name = isset($variants[$variantId]) ? $this->views->sizeName($variants[$variantId]->values) : '';

            if ($price === null) {
                $sizes[] = new OverlapSize($variantId, $name, null, null, false, 'no_price', null, false);

                continue;
            }

            $new = self::priceOn($sale, $price);
            $depth = $sale->depthOn($price);

            if ($new === null) {
                $sizes[] = new OverlapSize($variantId, $name, $this->views->money($price, $store), null, false, 'zero', $depth, $depth > $ceiling);

                continue;
            }

            // The size's candidates with this sale among them in place of itself, on a retail line (§1.6).
            $others = array_values(array_filter($candidates[$variantId] ?? [], static fn (Candidate $candidate): bool => $candidate->sourceId === null || $candidate->sourceId !== $itself));
            $winner = PriceResolution::resolve([...$others, new Candidate(SaleMode::Retail, 1, $new, PriceKind::Sale, $sale->alwaysWins, $startsAt, $endsAt, self::ITSELF)], SaleMode::Retail, 1, $at);
            $wins = $new->compareTo($price) < 0 && $winner !== null && $winner->sourceId === self::ITSELF;
            $why = match (true) {
                $wins => null,
                $new->compareTo($price) >= 0 => 'not_below_retail',
                $winner !== null && $winner->alwaysWins => 'always_wins',
                default => 'lower_price',
            };

            $sizes[] = new OverlapSize($variantId, $name, $this->views->money($price, $store), $this->views->money($new, $store), $wins, $why, $depth, $depth > $ceiling);
        }

        return $sizes;
    }

    /** Its price on a size: its own price, or its percentage off, half up to the coin - none at 0. */
    private static function priceOn(Sale $sale, Money $retail): ?Money
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
