<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeCell;
use Modules\Platform\Public\Dto\HomeColumn;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeStorePanel;
use Modules\Platform\Public\Dto\HomeTable;
use Modules\Platform\Public\Dto\HomeTableRow;
use Modules\Platform\Public\Dto\HomeValue;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Contracts\SalesReports;

/**
 * Home's **Best sellers** (dashboard.md §2 H): the 10 sizes sold most in the period, as the daily report reads them
 * (`SalesReports::productSales`, ops.md §1.11 item 4) - the photo, the name and size, the product's rating as the
 * shop shows it (Catalog's copy of Feedback's, `CatalogApi::productRatings`), the pieces sold and their Sales.
 * One store at a time behind store tabs for All Stores, as the charts.
 */
final readonly class BestSellersCard implements HomeCard
{
    /** How many sizes are listed (§2 H, as the daily report). */
    public const int LISTED = 10;

    public function __construct(
        private SalesHome $home,
        private SalesReports $reports,
        private CatalogApi $catalog,
        private PlatformApi $platform,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->home->stores($scope, SalesPermissions::ORDER_VIEW);

        if ($stores === null || $stores === []) {
            return null;
        }

        return new HomeCardData([], size: HomeSize::Full, panels: array_map(fn (StoreDto $store): HomeStorePanel => new HomeStorePanel(
            strtolower($store->code),
            SalesHome::name($store),
            table: $this->table($store, $scope),
        ), $stores));
    }

    private function table(StoreDto $store, HomeScope $scope): HomeTable
    {
        $range = $scope->range($store->timezone);
        $sold = $this->reports->productSales($store->storeId(), $range->from, $range->to, null, self::LISTED)->rows;
        $locale = SalesHome::locale();
        $rows = [];

        if ($sold !== []) {
            $productIds = array_values(array_unique(array_map(static fn ($row): string => $row->productId, $sold)));
            $variants = $this->catalog->variants(array_map(static fn ($row): string => $row->variantId, $sold));
            $products = $this->catalog->products($productIds);
            $cards = $this->catalog->productCards($store->storeId(), $locale, $productIds);
            $ratings = $this->catalog->productRatings($productIds);
            $photos = array_values(array_filter(array_map(static fn ($card): ?string => $card->photoMediaId, $cards)));
            $urls = $photos === [] ? [] : $this->platform->mediaUrlsOf($photos);

            foreach ($sold as $row) {
                $product = $products[strtolower($row->productId)] ?? null;
                $variant = $variants[strtolower($row->variantId)] ?? null;
                $card = $cards[strtolower($row->productId)] ?? null;
                $rating = $ratings[strtolower($row->productId)] ?? null;
                $name = $product === null ? '' : ($locale === 'en' ? ($product->nameEn ?? $product->nameAr) : $product->nameAr);
                $size = $variant === null ? '' : implode(' · ', array_map(static fn ($value): string => $value->attribute->in($locale).': '.$value->value->in($locale), $variant->values));

                $rows[] = new HomeTableRow(
                    $name,
                    [
                        new HomeCell($rating === null ? null : HomeValue::rating($rating['sum'] / $rating['count']), $rating === null ? '—' : null),
                        new HomeCell(HomeValue::count($row->units)),
                        new HomeCell(SalesHome::money($row->revenue, $store)),
                    ],
                    $size === '' ? null : $size,
                    SalesHome::thumb($card?->photoMediaId === null ? null : ($urls[strtolower($card->photoMediaId)] ?? null)),
                );
            }
        }

        return new HomeTable([
            new HomeColumn((string) __('sales::home.best_sellers.product')),
            new HomeColumn((string) __('sales::home.best_sellers.rating'), end: true),
            new HomeColumn((string) __('sales::home.best_sellers.pieces'), end: true),
            new HomeColumn((string) __('sales::home.best_sellers.sales'), end: true),
        ], $rows, (string) __('sales::home.best_sellers.empty'));
    }
}
