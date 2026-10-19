<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Dto\CategoryPathDto;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Pricing\Application\Query\ViewSizePrices\ReachingDiscount;
use Modules\Pricing\Application\Query\ViewSizePrices\ReachingSale;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Service\PercentOff;
use Modules\Pricing\Domain\Service\PriceResolution;
use Modules\Pricing\Domain\ValueObject\Candidate;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Modules\Pricing\Domain\ValueObject\Sale;
use Modules\Pricing\Domain\ValueObject\WindowState;
use Shared\Domain\ValueObject\Money;

/**
 * **What reaches a size** (pricing.md §4.3.3, the drawer and the product page): its sales and the
 * category discounts on its product, each with what it makes the size cost - read from the size's
 * candidates, which hold every price exactly as an order would be priced (§1.6) - or why it does not.
 * Pure: the reads hand it what they read.
 */
final readonly class ReachViews
{
    public function __construct(
        private PriceViews $views,
        private Words $words,
    ) {}

    /**
     * @param  list<Sale>  $sales  the sales not ended on the size
     * @param  list<Candidate>  $candidates  the size's candidates
     * @return list<ReachingSale>
     */
    public function sales(array $sales, string $variantId, ?Money $retail, array $candidates, DateTimeImmutable $now, StoreDto $store): array
    {
        $winner = PriceResolution::resolve($candidates, SaleMode::Retail, 1, $now);
        $reaching = [];

        foreach ($sales as $sale) {
            if (! in_array($variantId, $sale->variantIds, true)) {
                continue;
            }

            $price = self::priceOf($candidates, $sale->id) ?? ($retail === null ? null : self::saleOn($sale, $retail));
            $reaching[] = new ReachingSale(
                $sale->id,
                WindowState::of($sale->startsAt, $sale->endsAt, $now)->value,
                $sale->percentBasisPoints,
                $sale->price === null ? null : $this->views->money($sale->price, $store),
                $price === null ? null : $this->views->money($price, $store),
                $sale->startsAt->format(DateTimeInterface::ATOM),
                $sale->endsAt?->format(DateTimeInterface::ATOM),
                $sale->alwaysWins,
                $winner !== null && $winner->sourceId === $sale->id,
            );
        }

        return $reaching;
    }

    /**
     * @param  list<CategoryDiscount>  $discounts  the store's discounts not ended
     * @param  list<string>  $categoryIds  the product's category and every one above it
     * @param  list<Candidate>  $candidates  the size's candidates
     * @param  array<string, CategoryPathDto>  $paths  the discounts' categories
     * @return list<ReachingDiscount>
     */
    public function discounts(array $discounts, string $productId, array $categoryIds, ?Money $retail, array $candidates, array $paths, DateTimeImmutable $now, StoreDto $store): array
    {
        $winner = PriceResolution::resolve($candidates, SaleMode::Retail, 1, $now);
        $reaching = [];

        foreach (self::reaching($discounts, $productId, $categoryIds) as $discount) {
            $price = self::priceOf($candidates, $discount->id);
            // Skipped where it has no candidate on a priced size (§1.5; Q9): a fixed amount at or above the
            // price, or - in a wired store - deeper than the ceiling.
            $skipped = $price !== null || $retail === null ? null : ($discount->priceOn($retail) === null ? 'zero_or_below' : 'past_ceiling');
            $reaching[] = new ReachingDiscount(
                $discount->id,
                $discount->categoryId,
                $this->path($paths[$discount->categoryId] ?? null),
                WindowState::of($discount->startsAt, $discount->endsAt, $now)->value,
                $discount->percentBasisPoints,
                $discount->amount === null ? null : $this->views->money($discount->amount, $store),
                $discount->lines->value,
                $price === null ? null : $this->views->money($price, $store),
                $skipped,
                $discount->startsAt->format(DateTimeInterface::ATOM),
                $discount->endsAt?->format(DateTimeInterface::ATOM),
                $discount->alwaysWins,
                $winner !== null && $winner->sourceId === $discount->id,
            );
        }

        return $reaching;
    }

    /**
     * The discounts reaching a product (§1.5): on its category or one above it, not leaving it out.
     *
     * @param  list<CategoryDiscount>  $discounts
     * @param  list<string>  $categoryIds
     * @return list<CategoryDiscount>
     */
    public static function reaching(array $discounts, string $productId, array $categoryIds): array
    {
        return array_values(array_filter(
            $discounts,
            static fn (CategoryDiscount $discount): bool => in_array($discount->categoryId, $categoryIds, true) && ! in_array($productId, $discount->excludedProductIds, true),
        ));
    }

    /**
     * @return list<string> the names from the top down to the category, in the page's language
     */
    public function path(?CategoryPathDto $path): array
    {
        $locale = $this->words->locale();

        return $path === null ? [] : array_map(static fn (TranslatedTextDto $name): string => $name->in($locale), $path->path);
    }

    /**
     * A sale's or a discount's price on the size, as its candidate holds it - the same on either line; a
     * discount on wholesale lines only has its candidate there.
     *
     * @param  list<Candidate>  $candidates
     */
    private static function priceOf(array $candidates, string $sourceId): ?Money
    {
        foreach ($candidates as $candidate) {
            if ($candidate->sourceId === $sourceId) {
                return $candidate->amount;
            }
        }

        return null;
    }

    /** A sale's price on a size it has no candidate on: its own price, or its percentage off - none at 0. */
    private static function saleOn(Sale $sale, Money $retail): ?Money
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
