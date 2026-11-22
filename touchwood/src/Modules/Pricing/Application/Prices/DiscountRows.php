<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Query\ListCategoryDiscounts\DiscountRow;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Modules\Pricing\Domain\ValueObject\WindowState;

/**
 * **Category discounts as the screens show them** (pricing.md §4.3.3 P3): each category with the path to
 * it, each left-out product by name - read for the whole page at once, two queries however many rows.
 */
final readonly class DiscountRows
{
    public function __construct(
        private CatalogApi $catalog,
        private PriceViews $views,
        private ReachViews $reach,
        private Words $words,
    ) {}

    /**
     * @param  list<CategoryDiscount>  $discounts
     * @return list<DiscountRow>
     */
    public function rows(array $discounts, StoreDto $store, DateTimeImmutable $now): array
    {
        if ($discounts === []) {
            return [];
        }

        $paths = $this->catalog->categoriesWithPaths(array_values(array_unique(array_map(static fn (CategoryDiscount $discount): string => $discount->categoryId, $discounts))));
        $leftOut = array_values(array_unique(array_merge(...array_map(static fn (CategoryDiscount $discount): array => $discount->excludedProductIds, $discounts))));
        $products = $leftOut === [] ? [] : $this->catalog->products($leftOut);
        $english = $this->words->locale() === 'en';

        return array_map(fn (CategoryDiscount $discount): DiscountRow => new DiscountRow(
            $discount->id,
            $discount->categoryId,
            $this->reach->path($paths[$discount->categoryId] ?? null),
            $discount->percentBasisPoints,
            $discount->amount === null ? null : $this->views->money($discount->amount, $store),
            $discount->lines->value,
            $discount->startsAt->format(DateTimeInterface::ATOM),
            $discount->endsAt?->format(DateTimeInterface::ATOM),
            $discount->alwaysWins,
            WindowState::of($discount->startsAt, $discount->endsAt, $now)->value,
            $discount->excludedProductIds,
            array_map(static fn (string $id): string => self::name($products[$id] ?? null, $english), $discount->excludedProductIds),
        ), $discounts);
    }

    /** A product's name in the page's language; one with no English name yet shows its Arabic one. */
    public static function name(?ProductDto $product, bool $english): string
    {
        if ($product === null) {
            return '';
        }

        return $english ? ($product->nameEn ?? $product->nameAr) : $product->nameAr;
    }
}
