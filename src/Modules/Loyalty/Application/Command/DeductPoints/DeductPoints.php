<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\DeductPoints;

/** An admin removing points by hand — a correction (loyalty.md §1.9). */
final readonly class DeductPoints
{
    public function __construct(
        public string $customerId,
        public string $storeId,
        public int $points,
        public string $reason,
    ) {}
}
