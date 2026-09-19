<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateStaffProfile;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Staff\Avatars;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\PhoneAlreadyInUse;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\PhoneNumber;
use Modules\Access\Domain\ValueObject\StaffProfile;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

final readonly class UpdateStaffProfileHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private Avatars $avatars,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(UpdateStaffProfile $command): void
    {
        $this->rules->requireSomewhere(self::PERMISSION);

        $profile = StaffProfile::of($command->firstName, $command->lastName, $command->jobTitle, $command->dateOfBirth, $command->country, $command->address);
        $phone = PhoneNumber::of($command->phone);
        $this->avatars->requireUsable($command->avatarMediaId);

        $this->db->transaction(function () use ($command, $profile, $phone): void {
            $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);

            foreach ($this->rules->scopesFor($this->assignments->byStaff($target->id())?->staffStores()) as $scope) {
                $this->authorizer->authorize(self::PERMISSION, $scope);
            }

            // Never a Super Admin (they edit their own), an admin (unless by a Super Admin) or yourself.
            $author = $this->rules->author();
            $targetGrants = $this->grants->forStaff($target->id());
            $this->rules->requireManageable($author, $target, $targetGrants);

            // A cancelled account is final (amendment 29).
            if ($target->status() === StaffStatus::Cancelled) {
                throw new InvalidStaffStatus($target->status());
            }

            // A new phone receives the sign-in codes: it needs every action of their role.
            if ($target->phone() === null || ! $target->phone()->equals($phone)) {
                $this->rules->requireCoversActionsOf($author, $targetGrants);
            }

            if ($this->staff->phoneInUse($phone, $target->id())) {
                throw new PhoneAlreadyInUse;
            }

            $before = clone $target;
            $target->updateProfile($profile);
            $target->replacePhone($phone);
            $target->changeAvatar($command->avatarMediaId);
            $changed = $target->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->staff->update($target);
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.profile_updated', $before, $target, $changed));
        });
    }
}
