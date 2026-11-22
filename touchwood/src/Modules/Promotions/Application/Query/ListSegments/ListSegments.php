<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListSegments;

use DateTimeImmutable;

/** A store's segments, newest first (promotions.md §3, "Marketing › Segments"). */
final readonly class ListSegments
{
    public function __construct(
        public string $storeId,
        public ?DateTimeImmutable $beforeAt = null,
        public ?string $beforeId = null,
        public int $perPage = 50,
    ) {}
}
