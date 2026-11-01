<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * An order as Ops' messages about it need it (`SalesReports::orderForMessage`; ops.md §1.5): the number,
 * its state, the lines and totals as sold or as staff edited them, how it is paid and shipped, where it
 * goes, and the reasons staff gave. Amounts in the order's currency.
 */
final readonly class OrderMessageDto
{
    /**
     * @param  list<OrderMessageLineDto>  $lines
     */
    public function __construct(
        public string $orderId,
        public string $number,
        public string $storeId,
        public string $customerId,
        /** The language the customer placed it in. */
        public string $locale,
        /** `PENDING_APPROVAL`, `APPROVED`, `REJECTED`, `CANCELLED`, `COMPLETED`, `UNDELIVERED` (§1.4). */
        public string $status,
        /** `NOT_SHIPPED`, `SHIPPED`, `DELIVERED`, `CAME_BACK`. */
        public string $shipmentStatus,
        /** `ONLINE`, `BANK_TRANSFER`, `STAFF_CONTACT`. */
        public string $wayToPay,
        public array $lines,
        /** The lines' totals, before the coupon and the points. */
        public Money $subtotal,
        public Money $couponDiscount,
        public Money $pointsDiscount,
        public Money $shipping,
        public Money $vat,
        public Money $total,
        /** The carrier it shipped with, else the one ordered with (sales.md §9.5 #3; gap 6.7) — in `locale`. */
        public ?string $carrier,
        public ?string $trackingNumber,
        /** Staff's words when they rejected it; null otherwise. */
        public ?string $rejectionReason,
        /** Why it was cancelled (`CHANGED_MIND` … `BY_STAFF`); null while not cancelled. */
        public ?string $cancelReason,
        /** Who it is delivered to, as placed (or as staff last set it). */
        public string $recipientName,
        /** The delivery address as placed (or as staff last set it), formatted in the store's way. */
        public string $deliveryAddress,
        /** The full tracking link stored when it shipped; null: no Track (§9.5 #3, #11). */
        public ?string $trackingLink = null,
        /** No carrier served the place: the shipping fee is still to be set by staff (§9.5 #2). */
        public bool $shippingFeePending = false,
    ) {}
}
