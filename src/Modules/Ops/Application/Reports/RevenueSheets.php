<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Generator;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\RevenueDayDto;
use Shared\Domain\ValueObject\Money;

/**
 * The Revenue report (ops.md §1.10), from Sales' `revenueByDay` and `productSales`, Catalog for the
 * categories - each store in its own days, then one total per currency, never converted.
 *
 * **Revenue is net of refunds** (owner, 2026-10-28; §1.10): what customers pay for the goods after the coupon
 * and the points, without VAT and shipping, less the refunds of the returns asked that day and completed.
 * Where returns are still waiting, "revenue if the waiting refunds go through" takes their estimate off too,
 * and the Waiting refunds sheet names them by return and order number - never a customer.
 *
 * **VAT waits for Sales**: VAT net of refunds needs the refunds' VAT, which `revenueByDay` does not give yet
 * (owner, 2026-10-30) - its cells say so, never a number.
 *
 * **By category** is the goods sold, returns left in: only `productSales` gives revenue by product, and it
 * does not take returns off - they come off revenue on the day they were asked. Its note says so.
 */
final readonly class RevenueSheets
{
    private const int PAGE = 1000;

    public function __construct(
        private SalesReports $sales,
        private CatalogApi $catalog,
        private SizeNames $names,
    ) {}

    /**
     * @param  non-empty-list<StoreDto>  $stores
     * @return non-empty-list<ReportSheet>
     */
    public function sheets(array $stores, ReportPeriod $period, bool $byMonth, ReportWords $words): array
    {
        $days = [];
        foreach ($stores as $store) {
            $days[$store->id] = $this->sales->revenueByDay($store->storeId(), $period->start($store), $period->end($store));
        }

        return [
            new ReportSheet(
                $words->get('sheets.revenue'),
                [
                    $words->column('store', ColumnType::Text),
                    $byMonth ? $words->column('month', ColumnType::Month) : $words->column('day', ColumnType::Day),
                    $words->column('currency', ColumnType::Text),
                    $words->column('orders', ColumnType::Count),
                    $words->column('revenue', ColumnType::Money),
                    $words->column('revenue_if_waiting', ColumnType::Money),
                    $words->column('vat', ColumnType::Money),
                    $words->column('shipping', ColumnType::Money),
                    $words->column('refunds', ColumnType::Money),
                    $words->column('waiting_refunds', ColumnType::Money),
                ],
                [$words->get('notes.revenue'), $words->get('notes.waiting'), $words->get('notes.vat'), ...(count($stores) > 1 ? [$words->get('notes.totals')] : [])],
                $this->revenueRows($stores, $days, $byMonth, $words),
            ),
            new ReportSheet(
                $words->get('sheets.waiting_refunds'),
                [
                    $words->column('store', ColumnType::Text),
                    $words->column('asked_on', ColumnType::Day),
                    $words->column('return', ColumnType::Text),
                    $words->column('order', ColumnType::Text),
                    $words->column('currency', ColumnType::Text),
                    $words->column('estimate', ColumnType::Money),
                ],
                [$words->get('notes.waiting_list')],
                $this->waitingRows($stores, $days, $words),
            ),
            new ReportSheet(
                $words->get('sheets.categories'),
                [
                    $words->column('store', ColumnType::Text),
                    $words->column('category', ColumnType::Text),
                    $words->column('currency', ColumnType::Text),
                    $words->column('units', ColumnType::Count),
                    $words->column('sold', ColumnType::Money),
                ],
                [$words->get('notes.categories')],
                $this->categoryRows($stores, $period, $words),
            ),
        ];
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     * @param  array<string, list<RevenueDayDto>>  $days
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function revenueRows(array $stores, array $days, bool $byMonth, ReportWords $words): Generator
    {
        // The totals by currency, and its decimals.
        $exponents = $totals = [];
        foreach ($stores as $store) {
            $exponents[$store->currencyCode] = $store->currencyExponent;
            $totals[$store->currencyCode] = self::zeroFigures($store->currencyCode);
        }

        foreach ($stores as $store) {
            foreach (self::periods($days[$store->id], $byMonth, $store->currencyCode) as $period => $figures) {
                yield self::revenueRow($words->store($store), $period, $store->currencyCode, $store->currencyExponent, $figures, $words);
                $totals[$store->currencyCode] = self::add($totals[$store->currencyCode], $figures);
            }
        }

        foreach ($totals as $currency => $figures) {
            yield self::revenueRow($words->get('total'), null, $currency, $exponents[$currency], $figures, $words);
        }
    }

    /**
     * Each day's figures, or each month's summed.
     *
     * @param  list<RevenueDayDto>  $days
     * @return array<string, array{orders: int, revenue: Money, shipping: Money, refunds: Money, waiting: Money}>
     */
    private static function periods(array $days, bool $byMonth, string $currency): array
    {
        $periods = [];
        foreach ($days as $day) {
            $key = $byMonth ? substr($day->day, 0, 7) : $day->day;
            $periods[$key] = self::add($periods[$key] ?? self::zeroFigures($currency), [
                'orders' => $day->orders,
                'revenue' => $day->revenue,
                'shipping' => $day->shipping,
                'refunds' => $day->refunds,
                'waiting' => $day->waitingRefunds,
            ]);
        }

        return $periods;
    }

    /**
     * @param  array{orders: int, revenue: Money, shipping: Money, refunds: Money, waiting: Money}  $figures
     * @return list<string|int|ReportMoney|null>
     */
    private static function revenueRow(string $store, ?string $period, string $currency, int $exponent, array $figures, ReportWords $words): array
    {
        $money = static fn (Money $amount): ReportMoney => new ReportMoney($amount, $exponent);
        $net = $figures['revenue']->subtract($figures['refunds']);

        return [
            $store,
            $period,
            $currency,
            $figures['orders'],
            $money($net),
            $figures['waiting']->isZero() ? null : $money($net->subtract($figures['waiting'])),
            $words->get('waits_sales'),
            $money($figures['shipping']),
            $money($figures['refunds']),
            $money($figures['waiting']),
        ];
    }

    /** @return array{orders: int, revenue: Money, shipping: Money, refunds: Money, waiting: Money} */
    private static function zeroFigures(string $currency): array
    {
        $zero = Money::zero($currency);

        return ['orders' => 0, 'revenue' => $zero, 'shipping' => $zero, 'refunds' => $zero, 'waiting' => $zero];
    }

    /**
     * @param  array{orders: int, revenue: Money, shipping: Money, refunds: Money, waiting: Money}  $a
     * @param  array{orders: int, revenue: Money, shipping: Money, refunds: Money, waiting: Money}  $b
     * @return array{orders: int, revenue: Money, shipping: Money, refunds: Money, waiting: Money}
     */
    private static function add(array $a, array $b): array
    {
        return [
            'orders' => $a['orders'] + $b['orders'],
            'revenue' => $a['revenue']->add($b['revenue']),
            'shipping' => $a['shipping']->add($b['shipping']),
            'refunds' => $a['refunds']->add($b['refunds']),
            'waiting' => $a['waiting']->add($b['waiting']),
        ];
    }

    /**
     * @param  non-empty-list<StoreDto>  $stores
     * @param  array<string, list<RevenueDayDto>>  $days
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function waitingRows(array $stores, array $days, ReportWords $words): Generator
    {
        foreach ($stores as $store) {
            foreach ($days[$store->id] as $day) {
                foreach ($day->waiting as $waiting) {
                    yield [$words->store($store), $day->day, $waiting->returnId, $waiting->orderNumber, $store->currencyCode, new ReportMoney($waiting->estimate, $store->currencyExponent)];
                }
            }
        }
    }

    /**
     * Every size sold, grouped by its product's own category, most revenue first - per store.
     *
     * @param  non-empty-list<StoreDto>  $stores
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function categoryRows(array $stores, ReportPeriod $period, ReportWords $words): Generator
    {
        foreach ($stores as $store) {
            /** @var array<string, array{?string, int, Money}> $groups */
            $groups = [];
            $after = null;
            do {
                $page = $this->sales->productSales($store->storeId(), $period->start($store), $period->end($store), $after, self::PAGE);
                $products = $this->names->products(array_map(static fn ($row): string => $row->productId, $page->rows));
                foreach ($page->rows as $row) {
                    $category = ($products[$row->productId] ?? null)?->categoryId;
                    $groups[$category ?? ''] ??= [$category, 0, Money::zero($store->currencyCode)];
                    $groups[$category ?? ''][1] += $row->units;
                    $groups[$category ?? ''][2] = $groups[$category ?? ''][2]->add($row->revenue);
                }
                $after = $page->next;
            } while ($after !== null);

            uasort($groups, static fn (array $a, array $b): int => $b[2]->compareTo($a[2]));
            $paths = $this->catalog->categoriesWithPaths(array_values(array_filter(array_map(static fn (array $group): ?string => $group[0], array_values($groups)))));

            foreach ($groups as [$category, $units, $revenue]) {
                $path = $category === null ? null : ($paths[$category] ?? null);
                yield [
                    $words->store($store),
                    $path === null ? $words->get('no_category') : implode(' › ', array_map(static fn (TranslatedTextDto $name): string => $name->in($words->locale), $path->path)),
                    $store->currencyCode,
                    $units,
                    new ReportMoney($revenue, $store->currencyExponent),
                ];
            }
        }
    }
}
