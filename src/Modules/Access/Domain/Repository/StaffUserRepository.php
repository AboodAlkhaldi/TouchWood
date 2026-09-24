<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use DateTimeImmutable;
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
     * The staff member with this email, ignoring case — a cancelled account no longer has it
     * (amendment 29). Locks the row.
     */
    public function byEmail(EmailAddress $email): ?StaffUser;

    /**
     * Whether another staff member uses this email, ignoring case; a cancelled account does not.
     */
    public function emailInUse(EmailAddress $email, ?string $exceptStaffId = null): bool;

    /**
     * Whether another staff member uses this phone (owner's decision, 2026-09-19); a cancelled
     * account does not.
     */
    public function phoneInUse(PhoneNumber $phone, ?string $exceptStaffId = null): bool;

    /**
     * Invited Super Admins whose last invitation was sent before the cutoff, or who have none
     * (amendment 30). No lock: each is locked again when cancelled.
     *
     * @return list<string>
     */
    public function superAdminInvitationsSentBefore(DateTimeImmutable $cutoff): array;

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

    /**
     * The store this person is working in, or null when they have chosen none or the one they chose
     * has since been closed (stage 2b, P3).
     *
     * It is a preference, not permission: it is kept beside the account rather than inside
     * StaffUser, because it carries no rule of its own - who may choose which store is the
     * authorizer's answer, and every read still filters by the person's own stores.
     */
    public function currentStore(string $staffId): ?string;

    public function rememberCurrentStore(string $staffId, string $storeId): void;
}
