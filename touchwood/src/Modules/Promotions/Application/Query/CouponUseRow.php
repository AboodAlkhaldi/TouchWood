<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * One use of a coupon (promotions.md §3): the order's **public number**, never its id (handoff §5.3);
 * the customer by name; the discount it took, as it stands after any edit; when.
 */
final readonly class CouponUseRow
{
    public function __construct(
        public int $id,
        public string $orderNumber,
        public CustomerName $customer,
        public Money $discount,
        public DateTimeImmutable $usedAt,
    ) {}
}
