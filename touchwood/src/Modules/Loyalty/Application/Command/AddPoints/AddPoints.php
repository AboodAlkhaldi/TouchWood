<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Command\AddPoints;

/** An admin adding points by hand — a goodwill gift, a correction (loyalty.md §1.9). */
final readonly class AddPoints
{
    public function __construct(
        public string $customerId,
        public string $storeId,
        public int $points,
        public string $reason,
    ) {}
}
