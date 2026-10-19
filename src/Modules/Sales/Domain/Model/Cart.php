<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

use DateTimeImmutable;

/**
 * A buyer's cart in a store (sales.md §1.1): **permissive** — it holds whatever was added; the quote
 * checks it all. A customer's or a guest's, never both; the coupon typed, a customer's only; the lines
 * that left at sign-in, with why, until the cart next changes.
 */
final readonly class Cart
{
    /**
     * @param  list<CartLine>  $lines  in the order added
     * @param  list<RemovedLine>  $notice
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public ?string $customerId,
        public ?string $guestId,
        public ?string $couponCode,
        public array $lines,
        public array $notice,
        public DateTimeImmutable $updatedAt,
    ) {}
}
