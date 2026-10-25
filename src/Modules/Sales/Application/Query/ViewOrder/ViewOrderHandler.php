<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewOrder;

use Carbon\CarbonImmutable;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Query\Own\OrderLines;
use Modules\Sales\Application\Query\Own\OwnReads;
use Modules\Sales\Application\Query\Own\OwnReturnRow;
use Modules\Sales\Application\Query\Staff\EditRow;
use Modules\Sales\Application\Query\Staff\HistoryRow;
use Modules\Sales\Application\Query\Staff\MoneyRow;
use Modules\Sales\Application\Query\Staff\StaffOrderView;
use Modules\Sales\Application\Query\Staff\StaffReader;
use Modules\Sales\Application\Query\Staff\StaffReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Service\OrderStatuses;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **An order as staff see it** (sales.md §3 ViewOrder): its four machines, its customer by name and the contact
 * it was placed with, its lines and totals, the decision and the cancellation, the shipment, the money
 * recorded, the history, the edits, the ticks, the returns and the transfer documents — staff by name. An
 * order of a store the reader does not cover is no order.
 */
final readonly class ViewOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_VIEW;

    public function __construct(
        private OrderAccess $access,
        private StaffReads $reads,
        private OwnReads $own,
        private OrderLines $lines,
        private StaffReader $reader,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound
     */
    public function handle(ViewOrder $query): StaffOrderView
    {
        [$orderId] = $this->access->authorize(self::PERMISSION, $query->orderId);
        $order = $this->reads->order($orderId) ?? throw new OrderNotFound;
        $currency = (string) $order['currency_code'];
        $money = static fn (mixed $minor): Money => Money::of((int) $minor, $currency);
        $at = static fn (mixed $moment): ?CarbonImmutable => $moment === null ? null : CarbonImmutable::parse((string) $moment);
        $records = $this->reads->money($orderId);
        $history = $this->reads->history($orderId);
        $edits = $this->reads->edits($orderId);
        $ticks = $this->reads->ticks($orderId);
        $names = $this->reader->staff([
            $order['decided_by'] === null ? null : (string) $order['decided_by'], $order['cancelled_by'] === null ? null : (string) $order['cancelled_by'],
            ...array_map(static fn (array $row): ?string => $row['recorded_by'] === null ? null : (string) $row['recorded_by'], $records),
            ...array_map(static fn (array $row): ?string => $row['voided_by'] === null ? null : (string) $row['voided_by'], $records),
            ...array_map(static fn (array $row): ?string => $row['by'] === null ? null : (string) $row['by'], $history),
            ...array_map(static fn (array $row): string => (string) $row['by'], $edits),
            ...array_map(static fn (array $row): string => (string) $row['ticked_by'], $ticks),
        ]);
        $name = static fn (mixed $id): ?string => $id === null ? null : ($names[strtolower((string) $id)] ?? null);
        [$lines] = $this->lines->of($order);
        $payment = OrderStatuses::payment((int) $order['due_minor'], (int) $order['paid_minor'], (int) $order['refunded_minor']);
        /** @var array<string, mixed> $address */
        $address = json_decode((string) $order['address'], true, 512, JSON_THROW_ON_ERROR);
        [$customer, $mayView] = $this->reader->customer((string) $order['customer_id']);

        return new StaffOrderView(
            $orderId, (string) $order['number'], (string) $order['store_id'], CarbonImmutable::parse((string) $order['placed_at']),
            $customer,
            (string) $order['customer_name'],
            $order['customer_email'] === null || ! $mayView ? null : (string) $order['customer_email'], (string) $order['customer_phone'],
            (string) $order['audience'], (string) $order['status'], (string) $order['shipment_status'], $payment,
            OrderStatuses::customer((string) $order['status'], (string) $order['shipment_status'], $payment), WayToPay::from((string) $order['way_to_pay']),
            (bool) $order['may_ship_unpaid'], $at($order['short_of_stock_at']), $lines,
            $money($order['gross_subtotal_minor']), $money($order['net_subtotal_minor']), $order['coupon_code'] === null ? null : (string) $order['coupon_code'],
            $money($order['coupon_discount_minor']), (int) $order['points_used'], $money($order['points_discount_minor']), $money($order['goods_total_minor']),
            $money($order['shipping_fee_minor']), $money($order['vat_minor']), $money($order['order_total_minor']), $money($order['due_minor']),
            $money($order['paid_minor']), $money($order['refunded_minor']), $address,
            $name($order['decided_by']), $at($order['decided_at']), $order['decision_reason'] === null ? null : (string) $order['decision_reason'],
            $order['cancel_reason'] === null ? null : (string) $order['cancel_reason'], $order['cancel_other'] === null ? null : (string) $order['cancel_other'],
            $name($order['cancelled_by']), $at($order['cancelled_at']),
            $order['carrier'] === null ? null : (string) $order['carrier'], $order['tracking_number'] === null ? null : (string) $order['tracking_number'],
            $at($order['shipped_at']), $at($order['delivered_at']), $at($order['return_deadline_at']), $at($order['came_back_at']),
            array_map(static fn (array $row): MoneyRow => new MoneyRow(
                (string) $row['id'], (string) $row['kind'], $money($row['amount_minor']), (string) $row['way'],
                $row['reference'] === null ? null : (string) $row['reference'], $row['note'] === null ? null : (string) $row['note'],
                (string) $row['happened_on'], $name($row['recorded_by']), CarbonImmutable::parse((string) $row['recorded_at']),
                $name($row['voided_by']), $at($row['voided_at']), $row['void_reason'] === null ? null : (string) $row['void_reason'],
            ), $records),
            array_map(static function (array $row) use ($name): HistoryRow {
                /** @var array<string, mixed> $detail */
                $detail = json_decode((string) $row['detail'], true, 512, JSON_THROW_ON_ERROR);

                return new HistoryRow((string) $row['kind'], CarbonImmutable::parse((string) $row['at']), $name($row['by']), $row['edit_id'] === null ? null : (string) $row['edit_id'], $detail);
            }, $history),
            array_map(static fn (array $row): EditRow => new EditRow((string) $row['id'], (string) $row['reason'], $name($row['by']), CarbonImmutable::parse((string) $row['at'])), $edits),
            array_map(static fn (array $row): array => ['variantId' => (string) $row['variant_id'], 'by' => $name($row['ticked_by']), 'at' => CarbonImmutable::parse((string) $row['ticked_at'])], $ticks),
            array_map(static fn (array $return): OwnReturnRow => new OwnReturnRow(
                (string) $return['id'], (string) $return['status'], CarbonImmutable::parse((string) $return['requested_at']),
                $return['refund_due_minor'] === null ? null : $money($return['refund_due_minor']),
            ), $this->own->returns($orderId)),
            array_map(static fn (array $row): array => ['mediaId' => (string) $row['media_id'], 'position' => (int) $row['position'], 'uploadedAt' => CarbonImmutable::parse((string) $row['uploaded_at'])], $this->reads->transferDocuments($orderId)),
            $this->reader->exponent($currency),
        );
    }
}
