<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

/** A customer's points due to expire in the range asked (`LoyaltyApi::expiring`). */
final readonly class ExpiringPointsDto
{
    public function __construct(
        public string $customerId,
        /** What is left of every lot of theirs expiring in the range, together. */
        public int $points,
    ) {}
}
