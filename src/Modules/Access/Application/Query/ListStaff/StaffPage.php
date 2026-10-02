<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListStaff;

/**
 * One page of staff, and how many the reader may see in all. A Super Admin is in neither number:
 * they are invisible to everyone else, counts included (amendment 43) — and to another Super Admin
 * they come apart, in `superAdmins`, never among the admins (amendment 54).
 */
final readonly class StaffPage
{
    /**
     * @param  list<StaffSummary>  $staff  admins and staff; never a Super Admin
     * @param  list<StaffSummary>  $superAdmins  every Super Admin, for a Super Admin reader only;
     *                                           empty for anyone else
     */
    public function __construct(
        public array $staff,
        public int $total,
        public int $page,
        public int $perPage,
        public array $superAdmins = [],
    ) {}
}
