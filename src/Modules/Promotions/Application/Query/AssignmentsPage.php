<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;

/** A page of a coupon's assignments, newest first, with the cursor to the next (null at the end). */
final readonly class AssignmentsPage
{
    /**
     * @param  list<AssignmentRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?DateTimeImmutable $nextAt,
        public ?string $nextCustomerId,
    ) {}
}
