<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;

/** A page of a store's segments, newest first, with the cursor to the next (null at the end). */
final readonly class SegmentsPage
{
    /**
     * @param  list<SegmentRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?DateTimeImmutable $nextAt,
        public ?string $nextId,
    ) {}
}
