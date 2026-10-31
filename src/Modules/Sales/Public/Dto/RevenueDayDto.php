<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * One day of a store's revenue (`SalesReports::revenueByDay`; ops.md §1.10, the owner's rules of
 * 2026-10-28): what counts, by the store's own day.
 */
final readonly class RevenueDayDto
{
    /**
     * @param  list<WaitingRefundDto>  $waiting
     */
    public function __construct(
        /** The store's own day, `Y-m-d`. */
        public string $day,
        /** Orders placed that day that count: never cancelled, rejected or come back. */
        public int $orders,
        /** What their customers pay for the goods after the coupon and the points — without VAT, without shipping. */
        public Money $revenue,
        /** Their VAT as charged: the goods' and the shipping's. */
        public Money $vat,
        public Money $shipping,
        /** The goods refunded by returns asked that day and completed. */
        public Money $refunds,
        /** The goods the returns asked that day and not finished would refund, estimated. */
        public Money $waitingRefunds,
        public array $waiting,
        /** The goods' VAT those completed returns refunded (the shipping's never, as `refunds`). */
        public Money $refundsVat,
        /** The goods' VAT the returns not finished would refund, estimated as `waitingRefunds`. */
        public Money $waitingRefundsVat,
    ) {}
}
