<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sales\Domain\Service\ReturnRefunds;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\MoneyByWayDto;
use Modules\Sales\Public\Dto\MoneyDayDto;
use Modules\Sales\Public\Dto\MoneyDaysDto;
use Modules\Sales\Public\Dto\MoneyRecordMessageDto;
use Modules\Sales\Public\Dto\OrderMessageDto;
use Modules\Sales\Public\Dto\OrderMessageLineDto;
use Modules\Sales\Public\Dto\ProductNetSaleDto;
use Modules\Sales\Public\Dto\ProductNetSalesPageDto;
use Modules\Sales\Public\Dto\ProductSaleDto;
use Modules\Sales\Public\Dto\ProductSalesPageDto;
use Modules\Sales\Public\Dto\ReturnMessageDto;
use Modules\Sales\Public\Dto\RevenueDayDto;
use Modules\Sales\Public\Dto\SalesCountsDto;
use Modules\Sales\Public\Dto\TransfersWaitingDto;
use Modules\Sales\Public\Dto\WaitingRefundDto;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

/** `SalesReports` in SQL (sales.md §2.5): Ops' reads of orders, returns and money. */
final readonly class DatabaseSalesReports implements SalesReports
{
    public function __construct(
        private ConnectionInterface $db,
        private PlatformApi $platform,
    ) {}

    public function orderForMessage(string $orderId, string $locale): ?OrderMessageDto
    {
        $order = Ulids::valid($orderId) ? $this->db->table('sales.orders')->where('id', strtolower($orderId))->first() : null;

        if (! $order instanceof stdClass) {
            return null;
        }

        $arabic = $locale === 'ar';
        $money = static fn (mixed $minor): Money => Money::of((int) $minor, (string) $order->currency_code);
        /** @var array<string, mixed> $address */
        $address = json_decode((string) $order->address, true, 512, JSON_THROW_ON_ERROR);
        $lines = $this->db->table('sales.order_lines')->where('order_id', $order->id)->orderBy('is_gift')->orderBy('id')
            ->get(['name_ar', 'name_en', 'variant_values', 'quantity', 'total_minor', 'is_gift'])->all();

        return new OrderMessageDto(
            (string) $order->id, (string) $order->number, (string) $order->store_id, (string) $order->customer_id, (string) $order->locale,
            (string) $order->status, (string) $order->shipment_status, (string) $order->way_to_pay,
            array_values(array_map(static function (stdClass $line) use ($arabic, $money): OrderMessageLineDto {
                /** @var list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}> $values */
                $values = json_decode((string) $line->variant_values, true, 512, JSON_THROW_ON_ERROR);

                return new OrderMessageLineDto(
                    $arabic ? (string) $line->name_ar : (string) $line->name_en,
                    array_map(static fn (array $value): array => [
                        'attribute' => $arabic ? $value['attributeAr'] : $value['attributeEn'],
                        'value' => $arabic ? $value['valueAr'] : $value['valueEn'],
                    ], $values),
                    (int) $line->quantity, $money($line->total_minor), (bool) $line->is_gift,
                );
            }, $lines)),
            $money($order->net_subtotal_minor), $money($order->coupon_discount_minor), $money($order->points_discount_minor),
            $money($order->shipping_fee_minor), $money($order->vat_minor), $money($order->order_total_minor),
            $order->carrier === null ? null : (string) $order->carrier,
            $order->tracking_number === null ? null : (string) $order->tracking_number,
            $order->status === 'REJECTED' && $order->decision_reason !== null ? (string) $order->decision_reason : null,
            $order->cancel_reason === null ? null : (string) $order->cancel_reason,
            (string) ($address['recipientName'] ?? ''), (string) ($address['formatted'] ?? ''),
        );
    }

    public function moneyRecordForMessage(string $moneyRecordId): ?MoneyRecordMessageDto
    {
        if (! Ulids::valid($moneyRecordId)) {
            return null;
        }

        // A payment or a refund: the two tables' ids are ULIDs, so one id is in one of them at most.
        $row = $this->db->selectOne(<<<'SQL'
            SELECT m.*, o.number, o.customer_id FROM (
                SELECT 'PAYMENT' AS kind, id, order_id, store_id, currency_code, amount_minor, way, happened_on::text AS happened_on, voided_at FROM sales.payments WHERE id = ?
                UNION ALL
                SELECT 'REFUND', id, order_id, store_id, currency_code, amount_minor, way, happened_on::text, voided_at FROM sales.refunds WHERE id = ?
            ) AS m JOIN sales.orders AS o ON o.id = m.order_id
            LIMIT 1
            SQL, [strtolower($moneyRecordId), strtolower($moneyRecordId)]);

        return $row instanceof stdClass ? new MoneyRecordMessageDto(
            (string) $row->id, (string) $row->kind, (string) $row->order_id, (string) $row->number, (string) $row->store_id, (string) $row->customer_id,
            Money::of((int) $row->amount_minor, (string) $row->currency_code), (string) $row->way, (string) $row->happened_on, $row->voided_at !== null,
        ) : null;
    }

    public function returnForMessage(string $returnId): ?ReturnMessageDto
    {
        $row = Ulids::valid($returnId) ? $this->db->table('sales.returns as r')->join('sales.orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.id', strtolower($returnId))
            ->first(['r.id', 'r.order_id', 'o.number', 'r.store_id', 'o.customer_id', 'r.status', 'r.staff_reason', 'r.refund_due_minor', 'o.currency_code']) : null;

        return $row instanceof stdClass ? new ReturnMessageDto(
            (string) $row->id, (string) $row->order_id, (string) $row->number, (string) $row->store_id, (string) $row->customer_id, (string) $row->status,
            $row->status === 'REFUSED' && $row->staff_reason !== null ? (string) $row->staff_reason : null,
            $row->refund_due_minor === null ? null : Money::of((int) $row->refund_due_minor, (string) $row->currency_code),
        ) : null;
    }

    public function counts(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): SalesCountsDto
    {
        if ($from >= $to) {
            return new SalesCountsDto(0, 0, 0, 0, 0, 0, 0);
        }

        [$f, $t] = [Moment::of($from), Moment::of($to)];
        $in = static fn (string $column): string => "({$column} >= ? AND {$column} < ?)";
        $row = $this->db->selectOne(
            'SELECT count(*) FILTER (WHERE '.$in('placed_at').') AS placed,'
            .' count(*) FILTER (WHERE '.$in('placed_at')." AND status NOT IN ('CANCELLED', 'REJECTED', 'UNDELIVERED')) AS counted,"
            .' count(*) FILTER (WHERE '.$in('placed_at')." AND audience = 'PUBLIC') AS individuals,"
            .' count(*) FILTER (WHERE '.$in('placed_at')." AND audience = 'COMPANY') AS companies,"
            ." count(*) FILTER (WHERE status = 'CANCELLED' AND ".$in('cancelled_at').') AS cancelled,'
            ." count(*) FILTER (WHERE status = 'REJECTED' AND ".$in('decided_at').') AS rejected,'
            .' count(*) FILTER (WHERE '.$in('came_back_at').') AS came_back'
            .' FROM sales.orders WHERE store_id = ? AND ('.$in('placed_at').' OR '.$in('cancelled_at').' OR '.$in('decided_at').' OR '.$in('came_back_at').')',
            [$f, $t, $f, $t, $f, $t, $f, $t, $f, $t, $f, $t, $f, $t, $store->value, $f, $t, $f, $t, $f, $t, $f, $t],
        );

        return $row instanceof stdClass
            ? new SalesCountsDto((int) $row->placed, (int) $row->counted, (int) $row->individuals, (int) $row->companies, (int) $row->cancelled, (int) $row->rejected, (int) $row->came_back)
            : new SalesCountsDto(0, 0, 0, 0, 0, 0, 0);
    }

    public function moneyOn(StoreId $store, DateTimeImmutable $fromDay, DateTimeImmutable $toDay): MoneyDaysDto
    {
        $read = fn (string $table): array => array_values(array_map(
            static fn (stdClass $row): MoneyByWayDto => new MoneyByWayDto((string) $row->way, (int) $row->records, Money::of((int) $row->amount, (string) $row->currency_code)),
            $this->db->table($table)
                ->where('store_id', $store->value)->whereNull('voided_at')
                ->where('happened_on', '>=', $fromDay->format('Y-m-d'))->where('happened_on', '<=', $toDay->format('Y-m-d'))
                ->groupBy('way', 'currency_code')->orderBy('way')
                ->get(['way', 'currency_code', $this->db->raw('count(*) AS records'), $this->db->raw('sum(amount_minor) AS amount')])->all(),
        ));

        return new MoneyDaysDto($read('sales.payments'), $read('sales.refunds'));
    }

    public function moneyByDay(StoreId $store, DateTimeImmutable $fromDay, DateTimeImmutable $toDay): array
    {
        [$from, $to] = [$fromDay->format('Y-m-d'), $toDay->format('Y-m-d')];
        /** @var array<string, array{payments: list<MoneyByWayDto>, refunds: list<MoneyByWayDto>}> $days */
        $days = [];

        // Both tables at once, grouped in SQL by day, way and currency: one query for any range.
        foreach ($this->db->select(<<<'SQL'
            SELECT 'payments' AS kind, happened_on::text AS day, way, currency_code, count(*) AS records, sum(amount_minor) AS amount
            FROM sales.payments WHERE store_id = ? AND voided_at IS NULL AND happened_on BETWEEN ?::date AND ?::date
            GROUP BY 2, 3, 4
            UNION ALL
            SELECT 'refunds', happened_on::text, way, currency_code, count(*), sum(amount_minor)
            FROM sales.refunds WHERE store_id = ? AND voided_at IS NULL AND happened_on BETWEEN ?::date AND ?::date
            GROUP BY 2, 3, 4
            ORDER BY 2, 1, 3
            SQL, [$store->value, $from, $to, $store->value, $from, $to]) as $row) {
            $day = (string) $row->day;
            $days[$day] ??= ['payments' => [], 'refunds' => []];
            $days[$day][$row->kind === 'payments' ? 'payments' : 'refunds'][] = new MoneyByWayDto(
                (string) $row->way, (int) $row->records, Money::of((int) $row->amount, (string) $row->currency_code),
            );
        }

        return array_map(
            static fn (string $day, array $moved): MoneyDayDto => new MoneyDayDto($day, $moved['payments'], $moved['refunds']),
            array_keys($days), $days,
        );
    }

    public function transfersWaiting(StoreId $store): TransfersWaitingDto
    {
        $row = $this->db->table('sales.orders')
            ->where('store_id', $store->value)->where('way_to_pay', 'BANK_TRANSFER')
            ->whereIn('status', ['PENDING_APPROVAL', 'APPROVED', 'COMPLETED'])
            ->whereRaw('due_minor > paid_minor - refunded_minor')
            ->first([$this->db->raw('count(*) AS orders'), $this->db->raw('coalesce(sum(due_minor - (paid_minor - refunded_minor)), 0) AS owed')]);
        $currency = $this->platform->store($store)->currencyCode ?? throw new InvalidArgumentException('No such store.');

        return $row instanceof stdClass
            ? new TransfersWaitingDto((int) $row->orders, Money::of((int) $row->owed, $currency))
            : new TransfersWaitingDto(0, Money::zero($currency));
    }

    /** The statuses of an order that never counts as a sale (ops.md §1.10; sales.md §1.4). */
    private const array NOT_SALES = ['CANCELLED', 'REJECTED', 'UNDELIVERED'];

    /** The states of a return that refunds, or will: asked, accepted, received, completed. */
    private const array REFUNDING = ['REQUESTED', 'ACCEPTED', 'RECEIVED', 'COMPLETED'];

    /** The most rows a page of `productSales` or `productNetSales` holds. */
    public const int SALES_MAX = 1000;

    public function revenueByDay(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $place = $this->platform->store($store) ?? throw new InvalidArgumentException('No such store.');
        [$zone, $currency] = [$place->timezone, $place->currencyCode];

        if ($from >= $to) {
            return [];
        }

        [$f, $t] = [Moment::of($from), Moment::of($to)];
        /** @var array<string, array{int, int, int, int}> $sold orders, revenue, VAT and shipping, by day */
        $sold = [];
        /** @var array<string, int> $refunds */
        $refunds = [];
        /** @var array<string, int> $refundsVat */
        $refundsVat = [];
        /** @var array<string, list<WaitingRefundDto>> $waiting */
        $waiting = [];

        // The orders placed in the range that count, by the store's own day.
        foreach ($this->db->table('sales.orders')
            ->where('store_id', $store->value)->where('placed_at', '>=', $f)->where('placed_at', '<', $t)
            ->whereNotIn('status', self::NOT_SALES)
            ->groupByRaw('1')
            ->selectRaw("to_char(placed_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day, count(*) AS orders, sum(goods_total_minor) AS revenue, sum(vat_minor) AS vat, sum(shipping_fee_minor) AS shipping", [$zone])
            ->get() as $row) {
            $sold[(string) $row->day] = [(int) $row->orders, (int) $row->revenue, (int) $row->vat, (int) $row->shipping];
        }

        // The returns asked in the range that refund, or will: each by the day it was asked.
        $returns = $this->db->table('sales.returns as r')->join('sales.orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.store_id', $store->value)->where('r.requested_at', '>=', $f)->where('r.requested_at', '<', $t)
            ->whereIn('r.status', self::REFUNDING)
            ->orderBy('r.requested_at')->orderBy('r.id')
            ->selectRaw("r.id, r.order_id, r.status, o.number, to_char(r.requested_at AT TIME ZONE ?, 'YYYY-MM-DD') AS day", [$zone])
            ->get()->all();
        $goods = $this->goodsOf(array_values($returns));

        foreach ($returns as $return) {
            [$id, $key] = [(string) $return->id, (string) $return->day];

            if ($return->status === 'COMPLETED') {
                $refunds[$key] = ($refunds[$key] ?? 0) + $goods[$id]['goods'];
                $refundsVat[$key] = ($refundsVat[$key] ?? 0) + $goods[$id]['vat'];
            } else {
                $waiting[$key][] = new WaitingRefundDto($id, (string) $return->number, Money::of($goods[$id]['goods'], $currency), Money::of($goods[$id]['vat'], $currency));
            }
        }

        $days = array_unique([...array_keys($sold), ...array_keys($refunds), ...array_keys($waiting)]);
        sort($days);
        $money = static fn (int $minor): Money => Money::of($minor, $currency);

        return array_map(static function (string $day) use ($sold, $refunds, $refundsVat, $waiting, $money): RevenueDayDto {
            [$orders, $revenue, $vat, $shipping] = $sold[$day] ?? [0, 0, 0, 0];
            $list = $waiting[$day] ?? [];

            return new RevenueDayDto(
                $day, $orders, $money($revenue), $money($vat), $money($shipping), $money($refunds[$day] ?? 0),
                $money(array_sum(array_map(static fn (WaitingRefundDto $one): int => $one->estimate->minorUnits, $list))), $list,
                $money($refundsVat[$day] ?? 0),
                $money(array_sum(array_map(static fn (WaitingRefundDto $one): int => $one->estimateVat->minorUnits, $list))),
            );
        }, $days);
    }

    public function productSales(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after = null, int $limit = 100): ProductSalesPageDto
    {
        $currency = $this->platform->store($store)->currencyCode ?? throw new InvalidArgumentException('No such store.');
        $limit = min(max($limit, 1), self::SALES_MAX);

        if ($from >= $to) {
            return new ProductSalesPageDto([], null);
        }

        $bindings = [$store->value, Moment::of($from), Moment::of($to), ...self::NOT_SALES];
        $past = '';
        $cursor = $after === null ? [] : explode(':', strtolower($after));

        // A cursor is the last row's units and size; one that is not one starts at the top.
        if (count($cursor) === 2 && ctype_digit($cursor[0]) && Ulids::valid($cursor[1])) {
            $past = 'WHERE (-units, variant_id COLLATE "C") > (?, ? COLLATE "C")';
            array_push($bindings, -(int) $cursor[0], $cursor[1]);
        }

        $bindings[] = $limit + 1;
        $rows = $this->db->select(<<<SQL
            WITH sold AS (
                SELECT l.variant_id, min(l.product_id) AS product_id, sum(l.quantity) AS units,
                       sum(l.total_minor - l.coupon_share_minor - l.points_share_minor) AS revenue
                FROM sales.order_lines AS l JOIN sales.orders AS o ON o.id = l.order_id
                WHERE o.store_id = ? AND o.placed_at >= ? AND o.placed_at < ? AND o.status NOT IN (?, ?, ?) AND NOT l.is_gift
                GROUP BY l.variant_id
            )
            SELECT variant_id, product_id, units, revenue FROM sold {$past}
            ORDER BY -units, variant_id COLLATE "C"
            LIMIT ?
            SQL, $bindings);

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new ProductSalesPageDto(
            array_values(array_map(static fn (stdClass $row): ProductSaleDto => new ProductSaleDto(
                (string) $row->variant_id, (string) $row->product_id, (int) $row->units, Money::of((int) $row->revenue, $currency),
            ), $rows)),
            $more && $last !== null ? ((int) $last->units).':'.$last->variant_id : null,
        );
    }

    public function productNetSales(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after = null, int $limit = 100): ProductNetSalesPageDto
    {
        $currency = $this->platform->store($store)->currencyCode ?? throw new InvalidArgumentException('No such store.');
        $limit = min(max($limit, 1), self::SALES_MAX);

        if ($from >= $to) {
            return new ProductNetSalesPageDto([], null);
        }

        [$f, $t] = [Moment::of($from), Moment::of($to)];
        $bindings = [$store->value, $f, $t, ...self::NOT_SALES, $store->value, $f, $t, ...self::REFUNDING];
        $past = '';

        // A cursor is the last row's product; one that is not one starts at the top.
        if ($after !== null && Ulids::valid(strtolower($after))) {
            $past = 'WHERE product_id COLLATE "C" > ? COLLATE "C"';
            $bindings[] = strtolower($after);
        }

        $bindings[] = $limit + 1;
        // Every product sold in the range, or with a piece in a return asked in it — sold earlier, given back now.
        $rows = $this->db->select(<<<SQL
            WITH sold AS (
                SELECT l.product_id, sum(l.quantity) AS units, sum(l.total_minor - l.coupon_share_minor - l.points_share_minor) AS revenue
                FROM sales.order_lines AS l JOIN sales.orders AS o ON o.id = l.order_id
                WHERE o.store_id = ? AND o.placed_at >= ? AND o.placed_at < ? AND o.status NOT IN (?, ?, ?) AND NOT l.is_gift
                GROUP BY l.product_id
            ), returned AS (
                SELECT DISTINCT l.product_id
                FROM sales.returns AS r
                JOIN sales.return_lines AS rl ON rl.return_id = r.id
                JOIN sales.order_lines AS l ON l.id = rl.order_line_id
                WHERE r.store_id = ? AND r.requested_at >= ? AND r.requested_at < ? AND r.status IN (?, ?, ?, ?) AND NOT l.is_gift
            ), products AS (
                SELECT product_id FROM sold UNION SELECT product_id FROM returned
            )
            SELECT p.product_id, COALESCE(s.units, 0) AS units, COALESCE(s.revenue, 0) AS revenue
            FROM (SELECT product_id FROM products {$past}) AS p LEFT JOIN sold AS s ON s.product_id = p.product_id
            ORDER BY p.product_id COLLATE "C"
            LIMIT ?
            SQL, $bindings);

        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $page = array_map(static fn (stdClass $row): string => (string) $row->product_id, $rows);
        [$refunds, $waiting] = [[], []];

        // The page's products' refunds: the returns asked in the range holding a piece of one, each counted as
        // `revenueByDay` counts it, its goods kept by line.
        if ($page !== []) {
            $returns = $this->db->table('sales.returns as r')
                ->where('r.store_id', $store->value)->where('r.requested_at', '>=', $f)->where('r.requested_at', '<', $t)
                ->whereIn('r.status', self::REFUNDING)
                ->whereExists(fn ($lines) => $lines->selectRaw('1')->from('sales.return_lines as rl')
                    ->join('sales.order_lines as l', 'l.id', '=', 'rl.order_line_id')
                    ->whereColumn('rl.return_id', 'r.id')->whereIn('l.product_id', $page))
                ->orderBy('r.requested_at')->orderBy('r.id')
                ->get(['r.id', 'r.order_id', 'r.status'])->all();

            $done = [];

            foreach ($returns as $return) {
                $done[(string) $return->id] = $return->status === 'COMPLETED';
            }

            foreach ($this->goodsOf(array_values($returns)) as $id => $refund) {
                foreach ($refund['lines'] as $line) {
                    if ($done[$id] ?? false) {
                        $refunds[$line['product']] = ($refunds[$line['product']] ?? 0) + $line['goods'];
                    } else {
                        $waiting[$line['product']] = ($waiting[$line['product']] ?? 0) + $line['goods'];
                    }
                }
            }
        }

        $money = static fn (int $minor): Money => Money::of($minor, $currency);

        return new ProductNetSalesPageDto(
            array_values(array_map(static fn (stdClass $row): ProductNetSaleDto => new ProductNetSaleDto(
                (string) $row->product_id, (int) $row->units, $money((int) $row->revenue),
                $money($refunds[(string) $row->product_id] ?? 0), $money($waiting[(string) $row->product_id] ?? 0),
            ), $rows)),
            $more && $page !== [] ? $page[count($page) - 1] : null,
        );
    }

    /**
     * The goods each return refunds, or would, and their VAT (§1.8, `ReturnRefunds`): a completed one counted against the
     * returns of its order completed before it, as its completion did; one not finished against every return
     * completed so far and those not finished asked before it, over the pieces received, or else asked. By
     * return id: the goods, the goods' VAT, and the goods by line with the line's product.
     *
     * @param  list<stdClass>  $returns
     * @return array<string, array{goods: int, vat: int, lines: array<string, array{product: string, goods: int}>}>
     */
    private function goodsOf(array $returns): array
    {
        $orders = array_values(array_unique(array_map(static fn (stdClass $row): string => (string) $row->order_id, $returns)));

        if ($orders === []) {
            return [];
        }

        $lines = [];
        $productOf = [];

        foreach ($this->db->table('sales.order_lines')->whereIn('order_id', $orders)->orderBy('id')
            ->get(['id', 'order_id', 'product_id', 'quantity', 'total_minor', 'coupon_share_minor', 'points_share_minor', 'vat_share_minor', 'is_gift']) as $row) {
            $productOf[(string) $row->id] = (string) $row->product_id;
            $lines[(string) $row->order_id][(string) $row->id] = [
                'quantity' => (int) $row->quantity, 'total' => (int) $row->total_minor, 'coupon' => (int) $row->coupon_share_minor,
                'points' => (int) $row->points_share_minor, 'vat' => (int) $row->vat_share_minor, 'gift' => (bool) $row->is_gift,
            ];
        }

        // Every return of these orders that refunds, with its pieces: the completed ones in the order they completed.
        $pieces = [];
        $completed = [];
        $open = [];

        // The completed in the order they completed; those not finished after them, in the order they were asked.
        foreach ($this->db->table('sales.return_lines as rl')->join('sales.returns as r', 'r.id', '=', 'rl.return_id')
            ->whereIn('rl.order_id', $orders)->whereIn('r.status', self::REFUNDING)
            ->orderBy('r.completed_at')->orderBy('r.requested_at')->orderBy('r.id')
            ->get(['r.id', 'r.order_id', 'r.status', 'rl.order_line_id', 'rl.quantity', 'rl.received_quantity']) as $row) {
            $pieces[(string) $row->id][(string) $row->order_line_id] = $row->received_quantity === null ? (int) $row->quantity : (int) $row->received_quantity;

            if ($row->status === 'COMPLETED') {
                $completed[(string) $row->order_id][(string) $row->id] = true;
            } else {
                $open[(string) $row->order_id][(string) $row->id] = true;
            }
        }

        $goods = [];

        foreach ($returns as $return) {
            [$id, $order] = [(string) $return->id, (string) $return->order_id];
            $before = [];

            // Each before it: the completed ones, then — for one not finished — those not finished asked before it,
            // so several on one line land on the line's shares as their completions would.
            foreach ([...array_keys($completed[$order] ?? []), ...array_keys($open[$order] ?? [])] as $earlier) {
                if ($earlier === $id) {
                    break;
                }

                foreach ($pieces[$earlier] ?? [] as $line => $count) {
                    $before[$line] = ($before[$line] ?? 0) + $count;
                }
            }

            // The goods alone: the shipping is outside revenue, so it never rides along here — and so what the
            // refund holds beyond the goods is exactly their VAT.
            $refund = ReturnRefunds::of($lines[$order] ?? [], $before, $pieces[$id] ?? [], true, 0, 0);
            $byLine = [];

            // Each line moves on its own pieces alone, so its goods one at a time add up to the return's.
            foreach ($pieces[$id] ?? [] as $line => $count) {
                $byLine[$line] = [
                    'product' => $productOf[$line] ?? '',
                    'goods' => ReturnRefunds::of($lines[$order] ?? [], $before, [$line => $count], true, 0, 0)['goods'],
                ];
            }

            $goods[$id] = ['goods' => $refund['goods'], 'vat' => $refund['refund'] - $refund['goods'], 'lines' => $byLine];
        }

        return $goods;
    }
}
