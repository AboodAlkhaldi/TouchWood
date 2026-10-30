<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Generator;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\MoneyDaysDto;
use Modules\Sales\Public\Dto\RevenueDayDto;
use Shared\Domain\ValueObject\Money;

/**
 * The Revenue report (ops.md §1.10), from Sales' `revenueByDay` and `moneyOn` - each store in its own days,
 * then one total per currency, never converted.
 *
 * **Revenue is net of refunds** (owner, 2026-10-28; §1.10): what customers pay for the goods after the coupon
 * and the points, without VAT and shipping, less the refunds of the returns asked that day and completed.
 * Where returns are still waiting, "revenue if the waiting refunds go through" takes their estimate off too,
 * and the Waiting refunds sheet names them by return and order number - never a customer.
 *
 * **Waiting for Sales** - said, never a figure (owner, 2026-10-30):
 * - VAT net of refunds needs the refunds' VAT, which `revenueByDay` does not give yet;
 * - revenue **by category** must be net of refunds too, so the categories add up to the net total - and no
 *   Sales read gives refunds by product yet. Its sheet holds the columns and the note, no rows.
 *
 * **Money received** is beside it, by the day the money moved (§1.10: "shown beside it, by the day the
 * payment arrived"): payments in and refunds out, by way. `moneyOn` sums a range, so it is asked once for
 * the whole range - a store with none is passed over - then once per day or month.
 */
final readonly class RevenueSheets
{
    /**
     * The most days Money received is read day by day: `moneyOn` costs two queries a day, so a longer range
     * is read by month until Sales gives a read by day - and the sheet says so (coordinator, 2026-10-30).
     */
    public const int MONEY_DAYS = 93;

    public function __construct(
        private SalesReports $sales,
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
        $moneyByMonth = $byMonth || $period->days() > self::MONEY_DAYS;
        $moneyNotes = [$words->get('notes.money'), ...(! $byMonth && $moneyByMonth ? [$words->get('notes.money_by_month', ['days' => (string) self::MONEY_DAYS])] : []), ...$totals];

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
                    $moneyByMonth ? $words->column('month', ColumnType::Month) : $words->column('day', ColumnType::Day),
                    $words->column('currency', ColumnType::Text),
                    $words->column('way', ColumnType::Text),
                    $words->column('payments', ColumnType::Count),
                    $words->column('received', ColumnType::Money),
                    $words->column('refunds_made', ColumnType::Count),
                    $words->column('refunded', ColumnType::Money),
                ],
                $moneyNotes,
                $this->moneyRows($stores, $period, $moneyByMonth, $words),
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
                    $words->column('revenue', ColumnType::Money),
                ],
                [$words->get('notes.categories')],
                [],
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
                yield self::revenueRow($words->store($store), (string) $period, $store->currencyCode, $store->currencyExponent, $figures, $words);
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
     * Payments in and refunds out by way, per day or month, then one total per currency and way.
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
            $periods = $period->periods($store, $byMonth);
            if ($periods === []) {
                continue;
            }
            // The whole range first: a store that moved no money is not asked day by day.
            if (self::byWay($this->sales->moneyOn($store->storeId(), $periods[0][1], $periods[array_key_last($periods)][2]), $store->currencyCode) === []) {
                continue;
            }

            foreach ($periods as [$key, $first, $last]) {
                foreach (self::byWay($this->sales->moneyOn($store->storeId(), $first, $last), $store->currencyCode) as $way => $figures) {
                    yield self::moneyRow($words->store($store), $key, $store->currencyCode, $store->currencyExponent, $way, $figures, $words);
                    $sum = $totals[$store->currencyCode][$way] ?? [0, Money::zero($store->currencyCode), 0, Money::zero($store->currencyCode)];
                    $totals[$store->currencyCode][$way] = [$sum[0] + $figures[0], $sum[1]->add($figures[1]), $sum[2] + $figures[2], $sum[3]->add($figures[3])];
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
     * Payments and refunds side by side, by way, in Sales' order of the ways.
     *
     * @return array<string, array{int, Money, int, Money}> records in, amount in, records out, amount out
     */
    private static function byWay(MoneyDaysDto $money, string $currency): array
    {
        $ways = [];
        $zero = Money::zero($currency);
        foreach ($money->payments as $payment) {
            $ways[$payment->way] = [$payment->records, $payment->amount, 0, $zero];
        }
        foreach ($money->refunds as $refund) {
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
                    yield [$words->store($store), $day->day, $waiting->returnId, $waiting->orderNumber, $store->currencyCode, new ReportMoney($waiting->estimate, $store->currencyExponent)];
                }
            }
        }
    }
}
