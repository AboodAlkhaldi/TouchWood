<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Domain\ValueObject\PhoneNumber;

interface StaffUserRepository
{
    public function nextId(): string;

    /**
     * Locks the row until the transaction ends, so two changes to one staff member queue up.
     */
    public function byId(string $id): ?StaffUser;

    /**
     * A read with no lock, for answers to other modules.
     */
    public function find(string $id): ?StaffUser;

    /**
     * The staff member with this email, ignoring case. Locks the row.
     */
    public function byEmail(EmailAddress $email): ?StaffUser;

    /**
     * Whether another staff member uses this email, ignoring case.
     */
    public function emailInUse(EmailAddress $email, ?string $exceptStaffId = null): bool;

    /**
     * Whether another staff member uses this phone (owner's decision, 2026-09-19).
     */
    public function phoneInUse(PhoneNumber $phone, ?string $exceptStaffId = null): bool;

    /**
     * The Super Admins who have accepted their invitation, locked, so two revokes queue up.
     *
     * @return list<string>
     */
    public function activeSuperAdminIds(): array;

    /**
     * "First Last" for each id, in the order given; unknown ids are left out. No lock.
     *
     * @param  list<string>  $ids
     * @return array<string, string> id => name
     */
    public function names(array $ids): array;

    /**
     * @throws StaffEmailInUse|PhoneAlreadyInUse when another account took it at the same moment
     */
    public function add(StaffUser $staff): void;

    /**
     * @throws StaffEmailInUse|PhoneAlreadyInUse when another account took it at the same moment
     */
    public function update(StaffUser $staff): void;
}
