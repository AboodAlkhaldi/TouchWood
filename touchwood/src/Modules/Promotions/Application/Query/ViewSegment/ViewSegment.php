<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query\ViewSegment;

/** One segment by its id, for its own page (promotions.md §3). */
final readonly class ViewSegment
{
    public function __construct(
        public string $segmentId,
    ) {}
}
