<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Query\Home\OrdersNow;
use Modules\Sales\Application\Query\Home\RecentOrder;
use Modules\Sales\Application\Query\Home\SalesByAudience;
use Modules\Sales\Application\Query\Home\SalesHomeReads;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * `SalesHomeReads` through the query builder (CONVENTIONS.md, "Database: Laravel's tools first"): the sums
 * written as standard SQL `CASE WHEN`, nothing PostgreSQL's own.
 */
final readonly class DatabaseSalesHomeReads implements SalesHomeReads
{
    /** The statuses of an order that never counts as a sale, as `DatabaseSalesReports` reads them. */
    private const array NOT_SALES = ['CANCELLED', 'REJECTED', 'UNDELIVERED'];

    /** A return asked and not yet finished. */
    private const array WAITING_RETURNS = ['REQUESTED', 'ACCEPTED', 'RECEIVED'];

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function byAudience(StoreId $store, string $currency, DateTimeImmutable $from, DateTimeImmutable $to): SalesByAudience
    {
        $sums = ['PUBLIC' => 0, 'COMPANY' => 0];

        if ($from < $to) {
            foreach ($this->db->table('sales.orders')
                ->where('store_id', $store->value)->where('is_test', false)
                ->where('placed_at', '>=', Moment::of($from))->where('placed_at', '<', Moment::of($to))
                ->whereNotIn('status', self::NOT_SALES)
                ->groupBy('audience')
                ->get(['audience', $this->db->raw('sum(goods_total_minor) AS sales')]) as $row) {
                /** @var stdClass $row */
                $sums[(string) $row->audience] = (int) $row->sales;
            }
        }

        return new SalesByAudience(Money::of($sums['PUBLIC'], $currency), Money::of($sums['COMPANY'], $currency));
    }

    public function ordersNow(StoreId $store): OrdersNow
    {
        // Each tab's own condition (DatabaseStaffReads::FILTERED), test orders left out.
        $row = $this->db->table('sales.orders')
            ->where('store_id', $store->value)->where('is_test', false)
            ->first([
                $this->db->raw("coalesce(sum(CASE WHEN status = 'PENDING_APPROVAL' THEN 1 ELSE 0 END), 0) AS waiting"),
                $this->db->raw("coalesce(sum(CASE WHEN status = 'APPROVED' AND shipment_status = 'NOT_SHIPPED' THEN 1 ELSE 0 END), 0) AS to_ship"),
                $this->db->raw("coalesce(sum(CASE WHEN shipment_status = 'SHIPPED' THEN 1 ELSE 0 END), 0) AS on_the_way"),
            ]);

        return $row instanceof stdClass ? new OrdersNow((int) $row->waiting, (int) $row->to_ship, (int) $row->on_the_way) : new OrdersNow(0, 0, 0);
    }

    public function recentOrders(StoreId $store, int $limit): array
    {
        $orders = $this->db->table('sales.orders')
            ->where('store_id', $store->value)->where('is_test', false)
            ->orderByDesc('placed_at')->orderByDesc('id')->limit(min(max($limit, 1), 50))
            ->get(['id', 'number', 'placed_at', 'customer_name', 'way_to_pay', 'status', 'currency_code', 'order_total_minor'])->all();

        if ($orders === []) {
            return [];
        }

        /** @var array<string, list<stdClass>> $lines */
        $lines = [];

        foreach ($this->db->table('sales.order_lines')
            ->whereIn('order_id', array_map(static fn (stdClass $order): string => (string) $order->id, $orders))->where('is_gift', false)
            ->orderBy('order_id')->orderBy('id')
            ->get(['order_id', 'product_id', 'variant_id', 'name_ar', 'name_en', 'quantity']) as $line) {
            /** @var stdClass $line */
            $lines[(string) $line->order_id][] = $line;
        }

        return array_values(array_map(static function (stdClass $order) use ($lines): RecentOrder {
            $bought = $lines[(string) $order->id] ?? [];
            $first = $bought[0] ?? null;

            return new RecentOrder(
                (string) $order->id,
                (string) $order->number,
                CarbonImmutable::parse((string) $order->placed_at),
                (string) $order->customer_name,
                $first === null ? null : (string) $first->product_id,
                $first === null ? null : (string) $first->variant_id,
                $first === null ? '' : (string) $first->name_ar,
                $first === null ? '' : (string) $first->name_en,
                max(0, count($bought) - 1),
                array_sum(array_map(static fn (stdClass $line): int => (int) $line->quantity, $bought)),
                (string) $order->way_to_pay,
                (string) $order->status,
                Money::of((int) $order->order_total_minor, (string) $order->currency_code),
            );
        }, $orders));
    }

    public function returnsWaiting(StoreId $store): int
    {
        return $this->db->table('sales.returns as r')->join('sales.orders as o', 'o.id', '=', 'r.order_id')
            ->where('r.store_id', $store->value)->where('o.is_test', false)
            ->whereIn('r.status', self::WAITING_RETURNS)
            ->count();
    }
}
