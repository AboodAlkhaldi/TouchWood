<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\Model\EditedTotals;
use Modules\Sales\Domain\Model\NewOrder;
use Modules\Sales\Domain\Model\NewOrderLine;
use Modules\Sales\Domain\Model\OrderForEdit;
use Modules\Sales\Domain\Model\OrderLine;
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
            'wired' => $order->wired,
            'placed_at' => Moment::of($order->placedAt),
        ]);

        $this->insertLines($order->id, $order->storeId, $order->lines);
    }

    public function replaceLines(string $orderId, string $storeId, array $lines): void
    {
        $this->db->table('sales.order_lines')->where('order_id', $orderId)->delete();
        $this->insertLines($orderId, $storeId, $lines);
    }

    /** @param  list<NewOrderLine>  $lines */
    private function insertLines(string $orderId, string $storeId, array $lines): void
    {
        $this->db->table('sales.order_lines')->insert(array_map(static fn (NewOrderLine $line): array => [
            'id' => $line->id,
            'order_id' => $orderId,
            'store_id' => $storeId,
            'product_id' => $line->productId,
            'variant_id' => $line->variantId,
            'edit_id' => $line->editId,
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
            'counts_on_stock' => $line->countsOnStock,
        ], $lines));
    }

    public function lock(string $orderId): ?OrderState
    {
        $row = $this->db->table('sales.orders')->where('id', strtolower($orderId))->lockForUpdate()
            ->first(['id', 'store_id', 'number', 'customer_id', 'status', 'shipment_status', 'due_minor', 'paid_minor', 'refunded_minor', 'short_of_stock_at', 'currency_code', 'may_ship_unpaid', 'way_to_pay', 'goods_total_minor', 'wired']);

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
            (bool) $row->wired,
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

    public function countingOnStock(string $orderId): array
    {
        return array_values(array_map('strval', $this->db->table('sales.order_lines')->where('order_id', $orderId)->where('counts_on_stock', true)
            ->distinct()->orderBy('variant_id')->pluck('variant_id')->all()));
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

    public function forEdit(string $orderId): ?OrderForEdit
    {
        $row = $this->db->table('sales.orders')->where('id', strtolower($orderId))->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        // A size's parts oldest first: those placed with the order, then each edit's in turn.
        $lines = $this->db->table('sales.order_lines AS l')->leftJoin('sales.order_edits AS e', 'e.id', '=', 'l.edit_id')
            ->where('l.order_id', $row->id)->orderByRaw('l.edit_id IS NOT NULL, e.at, l.edit_id, l.id')->get(['l.*']);

        /** @var array<string, mixed> $address */
        $address = json_decode((string) $row->address, true, 512, JSON_THROW_ON_ERROR);

        return new OrderForEdit(
            (string) $row->id, (string) $row->store_id, (string) $row->customer_id, (string) $row->audience, (string) $row->currency_code,
            (int) $row->vat_rate_basis_points, (int) $row->shipping_rule_fee_minor, (int) $row->shipping_rule_free_above_minor,
            $row->coupon_id === null ? null : (string) $row->coupon_id, (int) $row->coupon_discount_minor, (int) $row->points_used, (int) $row->points_discount_minor,
            $row->gift_level_id === null ? null : (string) $row->gift_level_id, (int) $row->order_total_minor, (int) $row->paid_minor, (int) $row->refunded_minor,
            $address, (bool) $row->wired,
            array_values(array_map(static function (stdClass $line): OrderLine {
                /** @var list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}> $values */
                $values = json_decode((string) $line->variant_values, true, 512, JSON_THROW_ON_ERROR);

                return new OrderLine(
                    (string) $line->id, (string) $line->product_id, (string) $line->variant_id, $line->edit_id === null ? null : (string) $line->edit_id,
                    (bool) $line->is_gift, (string) $line->name_ar, (string) $line->name_en, $values, (string) $line->code, (string) $line->sale_mode,
                    $line->price_kind === null ? null : (string) $line->price_kind, (int) $line->quantity, (int) $line->unit_minor, (int) $line->list_unit_minor,
                    (int) $line->total_minor, (bool) $line->counts_on_stock,
                );
            }, $lines->all())),
        );
    }

    public function addEdit(string $editId, string $orderId, string $storeId, string $reason, string $by, DateTimeImmutable $at, array $before, array $after): void
    {
        $this->db->table('sales.order_edits')->insert([
            'id' => $editId, 'order_id' => $orderId, 'store_id' => $storeId, 'reason' => $reason, 'by' => $by, 'at' => Moment::of($at),
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }

    public function changeTotals(string $orderId, EditedTotals $totals): void
    {
        $this->db->table('sales.orders')->where('id', $orderId)->update([
            'coupon_discount_minor' => $totals->couponDiscount,
            'points_used' => $totals->pointsUsed,
            'points_discount_minor' => $totals->pointsDiscount,
            'gross_subtotal_minor' => $totals->grossSubtotal,
            'net_subtotal_minor' => $totals->netSubtotal,
            'goods_total_minor' => $totals->goodsTotal,
            'shipping_fee_minor' => $totals->shippingFee,
            'taxable_base_minor' => $totals->taxableBase,
            'vat_minor' => $totals->vat,
            'order_total_minor' => $totals->orderTotal,
            'due_minor' => $totals->orderTotal,
            'gift_level_id' => $totals->giftLevelId,
            ...($totals->address === null ? [] : ['address' => json_encode($totals->address, JSON_THROW_ON_ERROR)]),
        ]);
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
