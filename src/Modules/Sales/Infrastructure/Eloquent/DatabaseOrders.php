<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Domain\Model\NewOrder;
use Modules\Sales\Domain\Model\NewOrderLine;
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
