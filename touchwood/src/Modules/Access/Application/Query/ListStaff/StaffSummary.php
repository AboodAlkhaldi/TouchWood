<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ListStaff;

use Modules\Access\Public\Enums\StaffStatus;

/**
 * One row of the staff list. For an **admin** seen by anyone but a Super Admin, only the name and
 * the role are filled in: the job title, email, phone, stores, joining date and status are null or
 * empty (amendment 43; the status joined them, owner, 2026-09-21).
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
        /** Null for an admin the reader may not see in full: a colleague's account is not theirs to follow. */
        public ?StaffStatus $status,
        public ?string $roleId,
        public string $roleNameAr,
        public string $roleNameEn,
        public bool $isAdmin,
        public bool $allStores,
        public array $storeIds,
        public ?string $joinedAt,
        /** A Super Admin, only ever shown to another Super Admin, in a section of their own (amendment 54). */
        public bool $isSuperAdmin = false,
        /**
         * A Super Admin whose power was revoked: still hidden from everyone but a Super Admin, and
         * listed to them in the Super Admins section, marked as former (amendment 57).
         */
        public bool $formerSuperAdmin = false,
    ) {}
}
