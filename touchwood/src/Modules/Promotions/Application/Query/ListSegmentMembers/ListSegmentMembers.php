<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ListSegmentMembers;

/** A segment's members by name, a page at a time (promotions.md §1.5, §3). */
final readonly class ListSegmentMembers
{
    public function __construct(
        public string $segmentId,
        public ?string $afterCustomerId = null,
        public int $perPage = 50,
    ) {}
}
