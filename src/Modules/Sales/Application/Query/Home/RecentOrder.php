<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Home;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * One of a store's orders placed last (`SalesHomeReads::recentOrders`; dashboard.md §2 G): its first line as placed,
 * how many lines more, its pieces, the customer's name as placed, the way to pay, its state and its total.
 */
final readonly class RecentOrder
{
    public function __construct(
        public string $orderId,
        public string $number,
        public DateTimeImmutable $placedAt,
        public string $customerName,
        /** The first line bought (gifts left out), as placed: its product and size, its names. */
        public ?string $productId,
        public ?string $variantId,
        public string $nameAr,
        public string $nameEn,
        /** The lines bought after the first: "+2 more". */
        public int $moreLines,
        /** The pieces bought, gifts left out. */
        public int $pieces,
        /** A WayToPay value. */
        public string $wayToPay,
        /** The order's status, as the Orders list's badge names it. */
        public string $status,
        public Money $total,
    ) {}
}
