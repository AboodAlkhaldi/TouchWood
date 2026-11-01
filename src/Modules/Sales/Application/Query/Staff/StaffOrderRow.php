<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Staff;

use DateTimeImmutable;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Modules\Sales\Public\Enums\PaymentStatus;
use Shared\Domain\ValueObject\Money;

/** An order in staff's lists (sales.md §3 ListOrders): its four machines at a glance. */
final readonly class StaffOrderRow
{
    public function __construct(
        public string $orderId,
        public string $number,
        public string $storeId,
        public DateTimeImmutable $placedAt,
        public CustomerName $customer,
        public string $status,
        public string $shipment,
        public PaymentStatus $payment,
        public CustomerOrderStatus $customerStatus,
        public Money $total,
        public int $exponent,
        public bool $shortOfStock,
        public bool $mayShipUnpaid,
        /** No carrier served the place: staff set the fee before it ships (shipping.md §9.3 #8). */
        public bool $shippingFeePending = false,
    ) {}
}
