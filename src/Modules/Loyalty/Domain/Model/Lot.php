<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model;

use DateTimeImmutable;

/**
 * Points that came in together — earned on an order, or added by hand — with their own expiry date,
 * fixed when they came in (loyalty.md §1.3). Live while points are left and the date has not passed.
 */
final readonly class Lot
{
    public function __construct(
        public int $id,
        public string $accountId,
        public int $points,
        public int $pointsLeft,
        public DateTimeImmutable $expiresAt,
        public ?string $orderId,
    ) {}

    public function isLiveAt(DateTimeImmutable $now): bool
    {
        return $this->pointsLeft > 0 && $this->expiresAt > $now;
    }
}
