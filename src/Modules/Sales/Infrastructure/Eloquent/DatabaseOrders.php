<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\Model\NewOrder;
use Modules\Sales\Domain\Model\NewOrderLine;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Model\PlacedOrder;
use Modules\Sales\Domain\Repository\Orders;
use stdClass;

/** `Orders` in SQL, no Eloquent model (sales.md §5). */
final readonly class DatabaseOrders implements Orders
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function placedFrom(string $quoteId): ?PlacedOrder
    {
        $row = $this->db->table('sales.orders')->where('quote_id', strtolower($quoteId))->first(['id', 'number']);

        return $row instanceof stdClass ? new PlacedOrder((string) $row->id, (string) $row->number) : null;
    }

    public function add(NewOrder $order): void
    {
        $this->db->table('sales.orders')->insert([
            'id' => $order->id,
            'store_id' => $order->storeId,
            'number' => $order->number,
            'quote_id' => $order->quoteId,
            'customer_id' => $order->customerId,
            'audience' => $order->audience,
            'locale' => $order->locale,
            'currency_code' => $order->currencyCode,
            'customer_name' => $order->customerName,
            'customer_email' => $order->customerEmail,
            'customer_phone' => $order->customerPhone,
            'address' => json_encode($order->address, JSON_THROW_ON_ERROR),
            'status' => 'PENDING_APPROVAL',
            'shipment_status' => 'NOT_SHIPPED',
            'way_to_pay' => $order->wayToPay,
            'coupon_id' => $order->couponId,
            'coupon_code' => $order->couponCode,
            'coupon_discount_minor' => $order->couponDiscount,
            'points_used' => $order->pointsUsed,
            'points_discount_minor' => $order->pointsDiscount,
            'gross_subtotal_minor' => $order->grossSubtotal,
            'net_subtotal_minor' => $order->netSubtotal,
            'goods_total_minor' => $order->goodsTotal,
            'shipping_fee_minor' => $order->shippingFee,
            'shipping_rule_fee_minor' => $order->shippingRuleFee,
            'shipping_rule_free_above_minor' => $order->shippingRuleFreeAbove,
            'taxable_base_minor' => $order->taxableBase,
            'vat_minor' => $order->vat,
            'vat_rate_basis_points' => $order->vatRateBasisPoints,
            'order_total_minor' => $order->orderTotal,
            'due_minor' => $order->orderTotal,
            'paid_minor' => 0,
            'refunded_minor' => 0,
            'may_ship_unpaid' => false,
            'gift_level_id' => $order->giftLevelId,
            'placed_at' => Moment::of($order->placedAt),
        ]);

        $this->db->table('sales.order_lines')->insert(array_map(static fn (NewOrderLine $line): array => [
            'id' => $line->id,
            'order_id' => $order->id,
            'store_id' => $order->storeId,
            'product_id' => $line->productId,
            'variant_id' => $line->variantId,
            'edit_id' => null,
            'is_gift' => $line->isGift,
            'name_ar' => $line->nameAr,
            'name_en' => $line->nameEn,
            'variant_values' => json_encode($line->variantValues, JSON_THROW_ON_ERROR),
            'code' => $line->code,
            'sale_mode' => $line->saleMode,
            'price_kind' => $line->priceKind,
            'quantity' => $line->quantity,
            'unit_minor' => $line->unit,
            'list_unit_minor' => $line->listUnit,
            'total_minor' => $line->total,
            'coupon_share_minor' => $line->couponShare,
            'points_share_minor' => $line->pointsShare,
            'vat_share_minor' => $line->vatShare,
        ], $order->lines));
    }

    public function lock(string $orderId): ?OrderState
    {
        $row = $this->db->table('sales.orders')->where('id', strtolower($orderId))->lockForUpdate()
            ->first(['id', 'store_id', 'number', 'customer_id', 'status', 'shipment_status', 'due_minor', 'paid_minor', 'refunded_minor', 'short_of_stock_at', 'currency_code', 'may_ship_unpaid', 'way_to_pay', 'goods_total_minor']);

        return $row instanceof stdClass ? new OrderState(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->number,
            (string) $row->customer_id,
            (string) $row->status,
            (string) $row->shipment_status,
            (int) $row->due_minor,
            (int) $row->paid_minor,
            (int) $row->refunded_minor,
            $row->short_of_stock_at === null ? null : CarbonImmutable::parse((string) $row->short_of_stock_at),
            (string) $row->currency_code,
            (bool) $row->may_ship_unpaid,
            (string) $row->way_to_pay,
            (int) $row->goods_total_minor,
        ) : null;
    }

    public function storeOf(string $orderId): ?string
    {
        $store = $this->db->table('sales.orders')->where('id', strtolower($orderId))->value('store_id');

        return $store === null ? null : (string) $store;
    }

    public function approve(string $orderId, string $by, DateTimeImmutable $at): void
    {
        $this->db->table('sales.orders')->where('id', $orderId)->update(['status' => OrderState::APPROVED, 'decided_by' => $by, 'decided_at' => Moment::of($at)]);
    }

    public function reject(string $orderId, string $by, string $reason, DateTimeImmutable $at): void
    {
        $this->db->table('sales.orders')->where('id', $orderId)->update([
            'status' => OrderState::REJECTED, 'decided_by' => $by, 'decided_at' => Moment::of($at), 'decision_reason' => $reason, 'due_minor' => 0,
        ]);
    }

    public function cancel(string $orderId, string $reason, ?string $other, ?string $by, DateTimeImmutable $at): void
    {
        $this->db->table('sales.orders')->where('id', $orderId)->update([
            'status' => OrderState::CANCELLED, 'cancel_reason' => $reason, 'cancel_other' => $other, 'cancelled_by' => $by,
            'cancelled_at' => Moment::of($at), 'due_minor' => 0,
        ]);
    }

    public function flagShort(string $storeId, array $orderIds, DateTimeImmutable $at): array
    {
        $flagged = [];

        // In parts: PostgreSQL takes at most 65,535 bound values in one statement.
        foreach (array_chunk(array_values(array_unique(array_map('strtolower', $orderIds))), 1000) as $ids) {
            $rows = $this->db->select(sprintf(
                "UPDATE sales.orders SET short_of_stock_at = ? WHERE store_id = ? AND id IN (%s) AND short_of_stock_at IS NULL
                    AND status IN ('PENDING_APPROVAL', 'APPROVED') AND shipment_status = 'NOT_SHIPPED' RETURNING id",
                implode(', ', array_fill(0, count($ids), '?')),
            ), [Moment::of($at), $storeId, ...$ids]);

            foreach ($rows as $row) {
                $flagged[] = (string) $row->id;
            }
        }

        sort($flagged);

        return $flagged;
    }

    public function clearShort(string $orderId): bool
    {
        return $this->db->table('sales.orders')->where('id', $orderId)->whereNotNull('short_of_stock_at')->update(['short_of_stock_at' => null]) > 0;
    }

    public function moveMoney(string $orderId, int $paid, int $refunded): void
    {
        $this->db->update('UPDATE sales.orders SET paid_minor = paid_minor + ?, refunded_minor = refunded_minor + ? WHERE id = ?', [$paid, $refunded, $orderId]);
    }

    public function allowShipUnpaid(string $orderId, bool $allow): void
    {
        $this->db->table('sales.orders')->where('id', $orderId)->update(['may_ship_unpaid' => $allow]);
    }

    public function transferDocuments(string $orderId): array
    {
        return array_values(array_map('strval', $this->db->table('sales.transfer_documents')->where('order_id', $orderId)->orderBy('position')->pluck('media_id')->all()));
    }

    public function addTransferDocument(string $orderId, string $storeId, string $mediaId, int $position, DateTimeImmutable $at): void
    {
        $this->db->table('sales.transfer_documents')->insert(['order_id' => $orderId, 'store_id' => $storeId, 'media_id' => $mediaId, 'position' => $position, 'uploaded_at' => Moment::of($at)]);
    }

    public function pieces(string $orderId): array
    {
        $rows = $this->db->table('sales.order_lines')->where('order_id', $orderId)
            ->groupBy('variant_id', 'is_gift')->orderBy('variant_id')->orderBy('is_gift')
            ->selectRaw('variant_id, is_gift, SUM(quantity) AS quantity')->get();

        return array_values(array_map(static fn (stdClass $row): array => [
            'variantId' => (string) $row->variant_id, 'quantity' => (int) $row->quantity, 'gift' => (bool) $row->is_gift,
        ], $rows->all()));
    }

    public function ship(string $orderId, string $carrier, ?string $trackingNumber, DateTimeImmutable $at): void
    {
        $this->db->table('sales.orders')->where('id', $orderId)->update([
            'shipment_status' => OrderState::SHIPPED, 'shipped_at' => Moment::of($at), 'carrier' => $carrier, 'tracking_number' => $trackingNumber,
        ]);
    }

    public function deliver(string $orderId, DateTimeImmutable $at, DateTimeImmutable $returnDeadline): void
    {
        $this->db->table('sales.orders')->where('id', $orderId)->update([
            'status' => OrderState::COMPLETED, 'shipment_status' => 'DELIVERED', 'delivered_at' => Moment::of($at), 'return_deadline_at' => Moment::of($returnDeadline),
        ]);
    }

    public function cameBack(string $orderId, string $cameBackId, DateTimeImmutable $at): void
    {
        $this->db->table('sales.orders')->where('id', $orderId)->update([
            'status' => OrderState::UNDELIVERED, 'shipment_status' => 'CAME_BACK', 'came_back_at' => Moment::of($at), 'came_back_id' => $cameBackId, 'due_minor' => 0,
        ]);
    }

    public function ticked(string $orderId): array
    {
        return array_values(array_map('strval', $this->db->table('sales.order_ticks')->where('order_id', $orderId)->orderBy('variant_id')->pluck('variant_id')->all()));
    }

    public function tick(string $orderId, string $storeId, array $variantIds, string $by, DateTimeImmutable $at): void
    {
        $this->db->table('sales.order_ticks')->insertOrIgnore(array_map(static fn (string $variantId): array => [
            'order_id' => $orderId, 'store_id' => $storeId, 'variant_id' => $variantId, 'ticked_by' => $by, 'ticked_at' => Moment::of($at),
        ], $variantIds));
    }

    public function forgetEmail(string $customerId): void
    {
        $this->db->table('sales.orders')->where('customer_id', $customerId)->whereNotNull('customer_email')->update(['customer_email' => null]);
    }

    public function recordHistory(string $orderId, string $storeId, string $kind, ?string $editId, ?string $by, DateTimeImmutable $at, array $detail): void
    {
        $this->db->table('sales.order_history')->insert([
            'order_id' => $orderId,
            'store_id' => $storeId,
            'kind' => $kind,
            'edit_id' => $editId,
            'by' => $by,
            'at' => Moment::of($at),
            'detail' => json_encode($detail === [] ? (object) [] : $detail, JSON_THROW_ON_ERROR),
        ]);
    }
}
