<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;

/**
 * One customer an `ASSIGNED` coupon is given to (promotions.md §1.4): by name; when; the segment it came
 * from (null when picked otherwise, or the segment was deleted since); how many times they used it.
 */
final readonly class AssignmentRow
{
    public function __construct(
        public CustomerName $customer,
        public DateTimeImmutable $assignedAt,
        public ?string $segmentId,
        public ?string $segmentNameAr,
        public ?string $segmentNameEn,
        public int $uses,
    ) {}
}
