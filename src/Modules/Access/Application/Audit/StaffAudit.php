<?php

declare(strict_types=1);

namespace Modules\Access\Application\Audit;

use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;

/**
 * Audit entries for staff accounts. Names, email, phone, date of birth and address are personal:
 * recorded only as "changed" (spec §3.3). A staff account belongs to no single store, so the
 * entries are global.
 */
final class StaffAudit
{
    private const string SUBJECT = 'access.staff_user';

    public static function invited(StaffUser $staff): AuditEntryDto
    {
        $changes = self::personalProfile(AuditChanges::none())
            ->personal('email')
            ->personal('phone')
            ->changed('job_title', null, $staff->profile()->jobTitle)
            ->changed('country', null, $staff->profile()->country->value)
            ->changed('locale', null, $staff->language()->value)
            ->changed('status', null, $staff->status()->value)
            ->changed('is_super_admin', null, $staff->isSuperAdmin());

        return self::entry('access.staff_user.invited', $staff, $changes);
    }

    /**
     * Something that happened to the account, with the values it set (none of them personal).
     *
     * @param  array<string, string|bool|null>  $values  attribute => new value
     */
    public static function event(string $action, StaffUser $staff, array $values = []): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach ($values as $attribute => $value) {
            $changes->changed($attribute, null, $value);
        }

        return self::entry($action, $staff, $changes);
    }

    /**
     * @param  list<string>  $changed  StaffUser::pullChanges()
     */
    public static function updated(string $action, StaffUser $before, StaffUser $after, array $changed): AuditEntryDto
    {
        $changes = AuditChanges::none();

        foreach ($changed as $attribute) {
            match ($attribute) {
                'profile' => self::profileChanges($changes, $before->profile(), $after->profile()),
                'email', 'phone', 'password' => $changes->personal($attribute),
                'locale' => $changes->changed('locale', $before->language()->value, $after->language()->value),
                'status' => $changes->changed('status', $before->status()->value, $after->status()->value),
                'avatar_media_id' => $changes->changed('avatar_media_id', $before->avatarMediaId(), $after->avatarMediaId()),
                'is_super_admin' => $changes->changed('is_super_admin', $before->isSuperAdmin(), $after->isSuperAdmin()),
                default => $changes->personal($attribute),
            };
        }

        return self::entry($action, $after, $changes);
    }

    private static function profileChanges(AuditChanges $changes, StaffProfile $before, StaffProfile $after): AuditChanges
    {
        if ($before->firstName !== $after->firstName) {
            $changes->personal('first_name');
        }

        if ($before->lastName !== $after->lastName) {
            $changes->personal('last_name');
        }

        if ($before->dateOfBirth->format('Y-m-d') !== $after->dateOfBirth->format('Y-m-d')) {
            $changes->personal('date_of_birth');
        }

        if ($before->address !== $after->address) {
            $changes->personal('address');
        }

        if ($before->jobTitle !== $after->jobTitle) {
            $changes->changed('job_title', $before->jobTitle, $after->jobTitle);
        }

        if ($before->country->value !== $after->country->value) {
            $changes->changed('country', $before->country->value, $after->country->value);
        }

        return $changes;
    }

    private static function personalProfile(AuditChanges $changes): AuditChanges
    {
        return $changes->personal('first_name')->personal('last_name')->personal('date_of_birth')->personal('address');
    }

    private static function entry(string $action, StaffUser $staff, AuditChanges $changes): AuditEntryDto
    {
        return new AuditEntryDto($action, self::SUBJECT, $staff->id(), null, $changes);
    }
}
