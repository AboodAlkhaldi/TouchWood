<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * An order as Ops' messages about it need it (`SalesReports::orderForMessage`; ops.md §1.5): the number,
 * its state, the lines and totals as sold or as staff edited them, how it is paid and shipped, and the
 * reasons staff gave. Amounts in the order's currency.
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
        public ?string $carrier,
        public ?string $trackingNumber,
        /** Staff's words when they rejected it; null otherwise. */
        public ?string $rejectionReason,
        /** Why it was cancelled (`CHANGED_MIND` … `BY_STAFF`); null while not cancelled. */
        public ?string $cancelReason,
    ) {}
}
