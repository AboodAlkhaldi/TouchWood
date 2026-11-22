<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ListRedemptions;

use DateTimeImmutable;

/** A store's redemptions, newest first (loyalty.md §3, "Points › Redemptions"). */
final readonly class ListRedemptions
{
    public function __construct(
        public string $storeId,
        public ?DateTimeImmutable $beforeAt = null,
        public ?int $beforeId = null,
        public int $perPage = 50,
    ) {}
}
