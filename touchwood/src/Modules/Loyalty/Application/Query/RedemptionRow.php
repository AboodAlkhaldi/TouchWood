<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * One redemption of a store (loyalty.md §3, "Points › Redemptions"): the order's public number, the
 * customer (their id: the screen names them through Access), the points, the discount, the date.
 */
final readonly class RedemptionRow
{
    public function __construct(
        public int $id,
        public string $orderNumber,
        public string $customerId,
        public int $points,
        public Money $discount,
        public DateTimeImmutable $occurredAt,
    ) {}
}
