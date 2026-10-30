<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Generator;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Feedback\Public\Contracts\FeedbackApi;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Public\Contracts\SalesReports;
use Shared\Domain\ValueObject\Money;

/**
 * The four list reports (ops.md §1.10), each walking its module's paged read a thousand rows at a time and
 * naming each page through Catalog - so ten thousand sizes never sit in memory at once:
 *
 * - **Product sales** (Sales `productSales`): per size, units and revenue without VAT; returns are not taken
 *   off (they come off revenue on the day they were asked); then one total per currency.
 * - **Stock** (Inventory `stockLevels`): per size, now.
 * - **Searches** (Catalog `searchSummary`): per phrase, in each store's own days.
 * - **Favourites** (Feedback `favouriteCounts`): per product, now; an archived product kept and marked.
 */
final readonly class ListSheets
{
    private const int PAGE = 1000;

    public function __construct(
        private SalesReports $sales,
        private InventoryApi $inventory,
        private CatalogApi $catalog,
        private FeedbackApi $feedback,
        private SizeNames $names,
    ) {}

    /**
     * @param  non-empty-list<StoreDto>  $stores
     */
    public function productSales(array $stores, ReportPeriod $period, ReportWords $words): ReportSheet
    {
        return new ReportSheet(
            $words->get('sheets.product_sales'),
            [
                $words->column('store', ColumnType::Text),
                $words->column('product', ColumnType::Text),
                $words->column('size', ColumnType::Text),
                $words->column('code', ColumnType::Text),
                $words->column('currency', ColumnType::Text),
                $words->column('units', ColumnType::Count),
                $words->column('sold', ColumnType::Money),
            ],
            [$words->get('notes.product_sales'), ...(count($stores) > 1 ? [$words->get('notes.totals')] : [])],
            $this->productSalesRows($stores, $period, $words),
        );
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     */
    public function stock(array $stores, ReportWords $words): ReportSheet
    {
        return new ReportSheet(
            $words->get('sheets.stock'),
            [
                $words->column('store', ColumnType::Text),
                $words->column('product', ColumnType::Text),
                $words->column('size', ColumnType::Text),
                $words->column('code', ColumnType::Text),
                $words->column('in_stock', ColumnType::Count),
                $words->column('returned_extra', ColumnType::Count),
                $words->column('held', ColumnType::Count),
                $words->column('available', ColumnType::Count),
                $words->column('threshold', ColumnType::Count),
                $words->column('limited_by_stock', ColumnType::Text),
                $words->column('low', ColumnType::Text),
                $words->column('out', ColumnType::Text),
                $words->column('archived', ColumnType::Text),
            ],
            [$words->get('notes.stock')],
            $this->stockRows($stores, $words),
        );
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     */
    public function searches(array $stores, ReportPeriod $period, ReportWords $words): ReportSheet
    {
        return new ReportSheet(
            $words->get('sheets.searches'),
            [
                $words->column('store', ColumnType::Text),
                $words->column('language', ColumnType::Text),
                $words->column('phrase', ColumnType::Text),
                $words->column('searches', ColumnType::Count),
                $words->column('no_results', ColumnType::Count),
            ],
            [$words->get('notes.searches')],
            $this->searchRows($stores, $period, $words),
        );
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     */
    public function favourites(array $stores, ReportWords $words): ReportSheet
    {
        return new ReportSheet(
            $words->get('sheets.favourites'),
            [
                $words->column('store', ColumnType::Text),
                $words->column('product', ColumnType::Text),
                $words->column('archived', ColumnType::Text),
                $words->column('customers', ColumnType::Count),
            ],
            [$words->get('notes.favourites')],
            $this->favouriteRows($stores, $words),
        );
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function productSalesRows(array $stores, ReportPeriod $period, ReportWords $words): Generator
    {
        // The totals by currency: its decimals, the units and the revenue.
        $exponents = $units = $revenue = [];
        foreach ($stores as $store) {
            $exponents[$store->currencyCode] = $store->currencyExponent;
            $units[$store->currencyCode] = 0;
            $revenue[$store->currencyCode] = Money::zero($store->currencyCode);
        }

        foreach ($stores as $store) {
            $after = null;
            do {
                $page = $this->sales->productSales($store->storeId(), $period->start($store), $period->end($store), $after, self::PAGE);
                $names = $this->names->sizes(array_map(static fn ($row): string => $row->variantId, $page->rows), $words->locale);
                foreach ($page->rows as $row) {
                    $name = $names[$row->variantId] ?? null;
                    yield [$words->store($store), $name['product'] ?? '', $name['size'] ?? '', $name['code'] ?? '', $store->currencyCode, $row->units, new ReportMoney($row->revenue, $store->currencyExponent)];
                    $units[$store->currencyCode] += $row->units;
                    $revenue[$store->currencyCode] = $revenue[$store->currencyCode]->add($row->revenue);
                }
                $after = $page->next;
            } while ($after !== null);
        }

        foreach ($exponents as $currency => $exponent) {
            yield [$words->get('total'), null, null, null, $currency, $units[$currency], new ReportMoney($revenue[$currency], $exponent)];
        }
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function stockRows(array $stores, ReportWords $words): Generator
    {
        foreach ($stores as $store) {
            $after = null;
            do {
                $page = $this->inventory->stockLevels($store->storeId(), $after, self::PAGE);
                $names = $this->names->sizes(array_map(static fn ($row): string => $row->variantId, $page->rows), $words->locale);
                foreach ($page->rows as $row) {
                    $name = $names[$row->variantId] ?? null;
                    yield [
                        $words->store($store), $name['product'] ?? '', $name['size'] ?? '', $name['code'] ?? '',
                        $row->inStock, $row->returnedExtra, $row->held, $row->available, $row->threshold,
                        $words->yesNo($row->countsOnStock), $words->yesNo($row->low), $words->yesNo($row->out), $words->yesNo($row->archived),
                    ];
                }
                $after = $page->next;
            } while ($after !== null);
        }
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function searchRows(array $stores, ReportPeriod $period, ReportWords $words): Generator
    {
        foreach ($stores as $store) {
            $after = null;
            do {
                $page = $this->catalog->searchSummary($store->storeId(), $period->start($store), $period->end($store), $after, self::PAGE);
                foreach ($page->rows as $row) {
                    yield [$words->store($store), $words->get('languages.'.($row->locale === 'ar' ? 'ar' : 'en')), $row->query, $row->searches, $row->noResults];
                }
                $after = $page->next;
            } while ($after !== null);
        }
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function favouriteRows(array $stores, ReportWords $words): Generator
    {
        foreach ($stores as $store) {
            $after = null;
            do {
                $page = $this->feedback->favouriteCounts($store->storeId(), $after, self::PAGE);
                $products = $this->names->products(array_map(static fn ($row): string => $row->productId, $page->rows));
                foreach ($page->rows as $row) {
                    $product = $products[$row->productId] ?? null;
                    yield [$words->store($store), SizeNames::product($product, $words->locale), $words->yesNo(SizeNames::archived($product)), $row->customers];
                }
                $after = $page->next;
            } while ($after !== null);
        }
    }
}
