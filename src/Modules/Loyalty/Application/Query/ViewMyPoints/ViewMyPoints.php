<?php

declare(strict_types=1);

namespace Modules\Loyalty\Application\Query\ViewMyPoints;

use DateTimeImmutable;

/** A customer reading their own points in a store (loyalty.md §3). */
final readonly class ViewMyPoints
{
    public function __construct(
        public string $storeId,
        public ?DateTimeImmutable $beforeAt = null,
        public ?int $beforeId = null,
        public int $perPage = 50,
    ) {}
}
