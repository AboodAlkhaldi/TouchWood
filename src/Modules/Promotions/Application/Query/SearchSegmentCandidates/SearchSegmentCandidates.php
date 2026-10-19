<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\SearchSegmentCandidates;

/** Customers to pick by hand for a segment, found by name, email or phone (promotions.md §1.5). */
final readonly class SearchSegmentCandidates
{
    public function __construct(
        public string $segmentId,
        public string $text,
    ) {}
}
