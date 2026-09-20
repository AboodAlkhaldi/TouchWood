<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListStaff;

/**
 * One page of staff, and how many the reader may see in all. A Super Admin is in neither number:
 * they are invisible to everyone else, counts included (amendment 43).
 */
final readonly class StaffPage
{
    /**
     * @param  list<StaffSummary>  $staff
     */
    public function __construct(
        public array $staff,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
