<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListSegments;

use Modules\Promotions\Public\Dto\SegmentChoiceDto;

/** A page of a store's segments (ops.md §4.3, §4.4): `next` is what to ask for the following page, null on the last. */
final readonly class SegmentsPage
{
    /**
     * @param  list<SegmentChoiceDto>  $segments
     */
    public function __construct(
        public array $segments,
        public ?string $next,
    ) {}
}
