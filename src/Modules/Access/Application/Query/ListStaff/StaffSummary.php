<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListStaff;

use Modules\Access\Public\Enums\StaffStatus;

/**
 * One row of the staff list. For an **admin** seen by anyone but a Super Admin, only the name, the
 * role and the status are filled in: the job title, email, phone, stores and joining date are null
 * or empty (amendment 43).
 */
final readonly class StaffSummary
{
    /**
     * @param  list<string>  $storeIds  the stores they work in; empty when they cover every store,
     *                                  and empty for an admin the reader may not see in full
     */
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public ?string $jobTitle,
        public ?string $email,
        public ?string $phone,
        public StaffStatus $status,
        public ?string $roleId,
        public string $roleNameAr,
        public string $roleNameEn,
        public bool $isAdmin,
        public bool $allStores,
        public array $storeIds,
        public ?string $joinedAt,
    ) {}
}
