<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Modules\Pricing\Domain\ValueObject\Sale;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A sale's or a category discount's short description** (pricing.md §4.3.3, for Sync's report and the
 * notes), in the reader's language: "−10% · 60 cm, 80 cm" - its percentage or its price, then its sizes by
 * their values ("One Size" for a product with one) - and "Kitchens: 50.00 off" with the currency's sign. Read for many at
 * once: the sizes' values in one read of Catalog, the categories in one, each store from Platform's cache.
 */
final readonly class OfferNames
{
    /** Sizes a read of Catalog: under PostgreSQL's bind-parameter limit. */
    private const int CHUNK = 1000;

    public function __construct(
        private CatalogApi $catalog,
        private PlatformApi $platform,
        private PriceViews $views,
        private Words $words,
    ) {}

    /**
     * @param  list<Sale>  $sales
     * @param  array<string, VariantDto>  $known  sizes read already by the caller, not read again
     * @return array<string, string> by sale id
     */
    public function sales(array $sales, array $known = []): array
    {
        if ($sales === []) {
            return [];
        }

        $variants = $known;
        $missing = array_values(array_diff(array_unique(array_merge(...array_map(static fn (Sale $sale): array => $sale->variantIds, $sales))), array_keys($known)));

        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            $variants += $this->catalog->variants($chunk);
        }

        $names = [];

        foreach ($sales as $sale) {
            $sizes = implode($this->words->line('names.separator'), array_map(
                fn (string $id): string => isset($variants[$id]) ? $this->views->sizeName($variants[$id]->values) : '',
                $sale->variantIds,
            ));
            $store = $this->store($sale->storeId);

            $names[$sale->id] = $sale->percentBasisPoints !== null
                ? $this->words->line('names.sale_percent', ['percent' => self::percent($sale->percentBasisPoints), 'sizes' => $sizes])
                : $this->words->line('names.sale_price', ['price' => $sale->price === null || $store === null ? '' : ($this->views->money($sale->price, $store)->text ?? $sale->price->toDecimal($store->currencyExponent)), 'sizes' => $sizes]);
        }

        return $names;
    }

    /**
     * @param  list<CategoryDiscount>  $discounts
     * @return array<string, string> by discount id
     */
    public function discounts(array $discounts): array
    {
        if ($discounts === []) {
            return [];
        }

        $paths = $this->catalog->categoriesWithPaths(array_values(array_unique(array_map(static fn (CategoryDiscount $discount): string => $discount->categoryId, $discounts))));
        $locale = $this->words->locale();
        $names = [];

        foreach ($discounts as $discount) {
            $path = $paths[$discount->categoryId] ?? null;
            $category = $path === null ? '' : ($locale === 'en' ? $path->nameEn : $path->nameAr);
            $store = $this->store($discount->storeId);

            $names[$discount->id] = $discount->percentBasisPoints !== null
                ? $this->words->line('names.discount_percent', ['category' => $category, 'percent' => self::percent($discount->percentBasisPoints)])
                : $this->words->line('names.discount_amount', ['category' => $category, 'amount' => $discount->amount === null || $store === null ? '' : ($this->views->money($discount->amount, $store)->text ?? $discount->amount->toDecimal($store->currencyExponent))]);
        }

        return $names;
    }

    /** Basis points as a percentage, its decimals only where it has them: 1000 → "10", 1250 → "12.5". */
    public static function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.');
    }

    private function store(string $storeId): ?StoreDto
    {
        return $this->platform->store(StoreId::fromString($storeId));
    }
}
