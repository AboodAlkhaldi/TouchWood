<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use DateTimeImmutable;
use Modules\Sales\Public\Enums\CustomerOrderStatus;
use Shared\Domain\ValueObject\Money;

/** One of the customer's orders in their list (sales.md §3 ListOwnOrders): known by its number, never its id. */
final readonly class OwnOrderRow
{
    public function __construct(
        public string $number,
        public string $storeId,
        public DateTimeImmutable $placedAt,
        public CustomerOrderStatus $status,
        public Money $total,
        /** The pieces bought, the gift left out. */
        public int $pieces,
        /** The currency's exponent, to show `total`. */
        public int $exponent,
        /** No carrier served the place: the total is before shipping (sales.md §9.7 E7). */
        public bool $shippingPending = false,
    ) {}
}
