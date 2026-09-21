<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateOwnStaffProfile;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Staff\Avatars;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Every staff member, Super Admins included, for their own account only: the id comes from who is
 * signed in, never from the request (spec §1.5).
 */
final readonly class UpdateOwnStaffProfileHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private Avatars $avatars,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(UpdateOwnStaffProfile $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        $profile = StaffProfile::of($command->firstName, $command->lastName, $command->jobTitle, $command->dateOfBirth, $command->country, $command->address);
        $language = Language::of($command->locale);
        $this->avatars->requireUsable($command->avatarMediaId);

        $this->db->transaction(function () use ($command, $staffId, $profile, $language): void {
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);

            $before = clone $staff;
            $staff->updateProfile($profile);
            $staff->changeLanguage($language);
            $staff->changeAvatar($command->avatarMediaId);
            $changed = $staff->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->staff->update($staff);
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.own_profile_updated', $before, $staff, $changed));
        }, 3);
    }
}
