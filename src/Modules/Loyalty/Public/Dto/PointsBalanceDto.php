<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * A customer's points in one store, as they are now (loyalty.md §2.1, §2.2): only live lots count.
 */
final readonly class PointsBalanceDto
{
    public function __construct(
        public int $points,
        /** What they are worth in the store's currency. */
        public Money $worth,
        /** When the next points expire; null with no points. */
        public ?DateTimeImmutable $nextExpiry,
        /** How many expire then. */
        public int $nextExpiryPoints,
    ) {}
}
