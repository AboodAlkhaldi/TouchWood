<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Generator;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\MoneyByWayDto;
use Modules\Sales\Public\Dto\RevenueDayDto;
use Shared\Domain\ValueObject\Money;

/**
 * The Revenue report (ops.md §1.10), from Sales' `revenueByDay` and `moneyByDay` - each store in its own days,
 * then one total per currency, never converted.
 *
 * **Revenue is net of refunds** (owner, 2026-10-28; §1.10): what customers pay for the goods after the coupon
 * and the points, without VAT and shipping, less the refunds of the returns asked that day and completed.
 * Where returns are still waiting, "revenue if the waiting refunds go through" takes their estimate off too,
 * and the Waiting refunds sheet names them by return and order number - never a customer.
 *
 * **VAT is net of refunds too** (owner, 2026-10-30): the day's VAT less the goods' VAT of those refunds
 * (`refundsVat`); "VAT if the waiting refunds go through" takes the waiting ones' VAT off too
 * (`waitingRefundsVat`), each listed with its own (`estimateVat`). VAT is the goods' alone (owner: no VAT on
 * shipping) - Sales' `vat` is taken as it is, never adjusted here.
 *
 * **By category** is net of refunds too (owner, 2026-10-30), from Sales' `productNetSales`, each product under
 * its own category through Catalog, so the categories add up to the Revenue sheet's total; beside it the same
 * "revenue if the waiting refunds go through", empty where none wait (owner, 2026-11-01).
 *
 * **Money received** is beside it, by the day the money moved (§1.10: "shown beside it, by the day the
 * payment arrived"): payments in and refunds out, by way - `moneyByDay`, one read per store however long the
 * range, each month summed from its days when asked by month.
 */
final readonly class RevenueSheets
{
    /** The page `productNetSales` is walked with: the most it allows. */
    private const int PAGE = 1000;

    public function __construct(
        private SalesReports $sales,
        private CatalogApi $catalog,
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
        $totals = count($stores) > 1 ? [$words->get('notes.totals')] : [];
        $when = $byMonth ? $words->column('month', ColumnType::Month) : $words->column('day', ColumnType::Day);

        return [
            new ReportSheet(
                $words->get('sheets.revenue'),
                [
                    $words->column('store', ColumnType::Text),
                    $when,
                    $words->column('currency', ColumnType::Text),
                    $words->column('orders', ColumnType::Count),
                    $words->column('revenue', ColumnType::Money),
                    $words->column('revenue_if_waiting', ColumnType::Money),
                    $words->column('vat', ColumnType::Money),
                    $words->column('vat_if_waiting', ColumnType::Money),
                    $words->column('shipping', ColumnType::Money),
                    $words->column('refunds', ColumnType::Money),
                    $words->column('waiting_refunds', ColumnType::Money),
                ],
                [$words->get('notes.revenue'), $words->get('notes.waiting'), $words->get('notes.vat'), ...$totals],
                $this->revenueRows($stores, $days, $byMonth, $words),
            ),
            new ReportSheet(
                $words->get('sheets.money'),
                [
                    $words->column('store', ColumnType::Text),
                    $when,
                    $words->column('currency', ColumnType::Text),
                    $words->column('way', ColumnType::Text),
                    $words->column('payments', ColumnType::Count),
                    $words->column('received', ColumnType::Money),
                    $words->column('refunds_made', ColumnType::Count),
                    $words->column('refunded', ColumnType::Money),
                ],
                [$words->get('notes.money'), ...$totals],
                $this->moneyRows($stores, $period, $byMonth, $words),
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
                    $words->column('estimate_vat', ColumnType::Money),
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
                    $words->column('revenue', ColumnType::Money),
                    $words->column('revenue_if_waiting', ColumnType::Money),
                ],
                [$words->get('notes.categories'), ...$totals],
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
                yield self::revenueRow($words->store($store), (string) $period, $store->currencyCode, $store->currencyExponent, $figures);
                $totals[$store->currencyCode] = self::add($totals[$store->currencyCode], $figures);
            }
        }

        foreach ($totals as $currency => $figures) {
            yield self::revenueRow($words->get('total'), null, $currency, $exponents[$currency], $figures);
        }
    }

    /**
     * Each day's figures, or each month's summed.
     *
     * @param  list<RevenueDayDto>  $days
     * @return array<string, array{orders: int, revenue: Money, vat: Money, shipping: Money, refunds: Money, waiting: Money, waitingVat: Money}>
     */
    private static function periods(array $days, bool $byMonth, string $currency): array
    {
        $periods = [];
        foreach ($days as $day) {
            $key = $byMonth ? substr($day->day, 0, 7) : $day->day;
            $periods[$key] = self::add($periods[$key] ?? self::zeroFigures($currency), [
                'orders' => $day->orders,
                'revenue' => $day->revenue,
                // Net of the refunds' VAT, as revenue is of the refunds.
                'vat' => $day->vat->subtract($day->refundsVat),
                'shipping' => $day->shipping,
                'refunds' => $day->refunds,
                'waiting' => $day->waitingRefunds,
                'waitingVat' => $day->waitingRefundsVat,
            ]);
        }

        return $periods;
    }

    /**
     * @param  array{orders: int, revenue: Money, vat: Money, shipping: Money, refunds: Money, waiting: Money, waitingVat: Money}  $figures
     * @return list<string|int|ReportMoney|null>
     */
    private static function revenueRow(string $store, ?string $period, string $currency, int $exponent, array $figures): array
    {
        $money = static fn (Money $amount): ReportMoney => new ReportMoney($amount, $exponent);
        $net = $figures['revenue']->subtract($figures['refunds']);
        $waits = ! $figures['waiting']->isZero() || ! $figures['waitingVat']->isZero();

        return [
            $store,
            $period,
            $currency,
            $figures['orders'],
            $money($net),
            $waits ? $money($net->subtract($figures['waiting'])) : null,
            $money($figures['vat']),
            $waits ? $money($figures['vat']->subtract($figures['waitingVat'])) : null,
            $money($figures['shipping']),
            $money($figures['refunds']),
            $money($figures['waiting']),
        ];
    }

    /** @return array{orders: int, revenue: Money, vat: Money, shipping: Money, refunds: Money, waiting: Money, waitingVat: Money} */
    private static function zeroFigures(string $currency): array
    {
        $zero = Money::zero($currency);

        return ['orders' => 0, 'revenue' => $zero, 'vat' => $zero, 'shipping' => $zero, 'refunds' => $zero, 'waiting' => $zero, 'waitingVat' => $zero];
    }

    /**
     * @param  array{orders: int, revenue: Money, vat: Money, shipping: Money, refunds: Money, waiting: Money, waitingVat: Money}  $a
     * @param  array{orders: int, revenue: Money, vat: Money, shipping: Money, refunds: Money, waiting: Money, waitingVat: Money}  $b
     * @return array{orders: int, revenue: Money, vat: Money, shipping: Money, refunds: Money, waiting: Money, waitingVat: Money}
     */
    private static function add(array $a, array $b): array
    {
        return [
            'orders' => $a['orders'] + $b['orders'],
            'revenue' => $a['revenue']->add($b['revenue']),
            'vat' => $a['vat']->add($b['vat']),
            'shipping' => $a['shipping']->add($b['shipping']),
            'refunds' => $a['refunds']->add($b['refunds']),
            'waiting' => $a['waiting']->add($b['waiting']),
            'waitingVat' => $a['waitingVat']->add($b['waitingVat']),
        ];
    }

    /**
     * Payments in and refunds out by way, per day or month, then one total per currency and way: one
     * `moneyByDay` read per store, over its own days in the range.
     *
     * @param  non-empty-list<StoreDto>  $stores
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function moneyRows(array $stores, ReportPeriod $period, bool $byMonth, ReportWords $words): Generator
    {
        /** @var array<string, int> $exponents */
        $exponents = [];
        /** @var array<string, array<string, array{int, Money, int, Money}>> $totals by currency, then way */
        $totals = [];

        foreach ($stores as $store) {
            $exponents[$store->currencyCode] = $store->currencyExponent;
            $days = $period->storeDays($store);
            if ($days === null) {
                continue;
            }

            // Each day's ways, or each month's summed from its days.
            /** @var array<string, array<string, array{int, Money, int, Money}>> $periods by day or month, then way */
            $periods = [];
            foreach ($this->sales->moneyByDay($store->storeId(), $days[0], $days[1]) as $day) {
                $key = $byMonth ? substr($day->day, 0, 7) : $day->day;
                foreach (self::byWay($day->payments, $day->refunds, $store->currencyCode) as $way => $figures) {
                    $periods[$key][$way] = self::addWay($periods[$key][$way] ?? null, $figures);
                }
            }

            foreach ($periods as $key => $ways) {
                foreach ($ways as $way => $figures) {
                    yield self::moneyRow($words->store($store), $key, $store->currencyCode, $store->currencyExponent, $way, $figures, $words);
                    $totals[$store->currencyCode][$way] = self::addWay($totals[$store->currencyCode][$way] ?? null, $figures);
                }
            }
        }

        foreach ($totals as $currency => $ways) {
            foreach ($ways as $way => $figures) {
                yield self::moneyRow($words->get('total'), null, $currency, $exponents[$currency], $way, $figures, $words);
            }
        }
    }

    /**
     * @param  array{int, Money, int, Money}|null  $sum
     * @param  array{int, Money, int, Money}  $figures
     * @return array{int, Money, int, Money}
     */
    private static function addWay(?array $sum, array $figures): array
    {
        return $sum === null ? $figures : [$sum[0] + $figures[0], $sum[1]->add($figures[1]), $sum[2] + $figures[2], $sum[3]->add($figures[3])];
    }

    /**
     * Payments and refunds side by side, by way, in Sales' order of the ways.
     *
     * @param  list<MoneyByWayDto>  $payments
     * @param  list<MoneyByWayDto>  $refunds
     * @return array<string, array{int, Money, int, Money}> records in, amount in, records out, amount out
     */
    private static function byWay(array $payments, array $refunds, string $currency): array
    {
        $ways = [];
        $zero = Money::zero($currency);
        foreach ($payments as $payment) {
            $ways[$payment->way] = [$payment->records, $payment->amount, 0, $zero];
        }
        foreach ($refunds as $refund) {
            $ways[$refund->way] = [$ways[$refund->way][0] ?? 0, $ways[$refund->way][1] ?? $zero, $refund->records, $refund->amount];
        }

        return array_filter($ways, static fn (array $figures): bool => $figures[0] > 0 || $figures[2] > 0);
    }

    /**
     * @param  array{int, Money, int, Money}  $figures
     * @return list<string|int|ReportMoney|null>
     */
    private static function moneyRow(string $store, ?string $period, string $currency, int $exponent, string $way, array $figures, ReportWords $words): array
    {
        return [$store, $period, $currency, $words->way($way), $figures[0], new ReportMoney($figures[1], $exponent), $figures[2], new ReportMoney($figures[3], $exponent)];
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
                    yield [$words->store($store), $day->day, $waiting->returnId, $waiting->orderNumber, $store->currencyCode, new ReportMoney($waiting->estimate, $store->currencyExponent), new ReportMoney($waiting->estimateVat, $store->currencyExponent)];
                }
            }
        }
    }

    /**
     * Each store's categories over the whole range, most revenue first, then one total per currency.
     *
     * @param  non-empty-list<StoreDto>  $stores
     * @return Generator<int, list<string|int|ReportMoney|null>>
     */
    private function categoryRows(array $stores, ReportPeriod $period, ReportWords $words): Generator
    {
        // The totals by currency: its decimals, net revenue and the refunds waiting.
        $exponents = $net = $waiting = [];
        foreach ($stores as $store) {
            $exponents[$store->currencyCode] = $store->currencyExponent;
            $net[$store->currencyCode] = $waiting[$store->currencyCode] = Money::zero($store->currencyCode);
        }

        foreach ($stores as $store) {
            $groups = $this->categoryFigures($store, $period);
            $paths = $this->catalog->categoriesWithPaths(array_values(array_filter(array_keys($groups), static fn (string $category): bool => $category !== '')));
            uasort($groups, static fn (array $a, array $b): int => $b['net']->compareTo($a['net']));

            foreach ($groups as $category => $figures) {
                $path = $paths[$category] ?? null;
                $name = $path === null ? $words->get('no_category') : implode(' › ', array_map(static fn ($step): string => $step->in($words->locale), $path->path));
                yield self::categoryRow($words->store($store), $name, $store->currencyCode, $store->currencyExponent, $figures['net'], $figures['waiting']);
                $net[$store->currencyCode] = $net[$store->currencyCode]->add($figures['net']);
                $waiting[$store->currencyCode] = $waiting[$store->currencyCode]->add($figures['waiting']);
            }
        }

        foreach ($net as $currency => $revenue) {
            yield self::categoryRow($words->get('total'), null, $currency, $exponents[$currency], $revenue, $waiting[$currency]);
        }
    }

    /**
     * One store's net revenue and refunds waiting by category id, a product with no category - or one Catalog
     * no longer knows - under the empty id: a page of Sales' products and their categories at a time.
     *
     * @return array<string, array{net: Money, waiting: Money}>
     */
    private function categoryFigures(StoreDto $store, ReportPeriod $period): array
    {
        $zero = Money::zero($store->currencyCode);
        $groups = [];
        $after = null;
        do {
            $page = $this->sales->productNetSales($store->storeId(), $period->start($store), $period->end($store), $after, self::PAGE);
            $products = $this->catalog->products(array_map(static fn ($row): string => $row->productId, $page->rows));
            foreach ($page->rows as $row) {
                $key = $products[$row->productId]->categoryId ?? '';
                $group = $groups[$key] ?? ['net' => $zero, 'waiting' => $zero];
                $groups[$key] = ['net' => $group['net']->add($row->revenue)->subtract($row->refunds), 'waiting' => $group['waiting']->add($row->waitingRefunds)];
            }
            $after = $page->next;
        } while ($after !== null);

        return $groups;
    }

    /** @return list<string|int|ReportMoney|null> */
    private static function categoryRow(string $store, ?string $category, string $currency, int $exponent, Money $net, Money $waiting): array
    {
        return [
            $store,
            $category,
            $currency,
            new ReportMoney($net, $exponent),
            $waiting->isZero() ? null : new ReportMoney($net->subtract($waiting), $exponent),
        ];
    }
}
