<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResendStaffInvitation;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Staff\Invitations;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

final readonly class ResendStaffInvitationHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_INVITE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private Invitations $invitations,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(ResendStaffInvitation $command): void
    {
        // Before anything is looked up, so someone without the action learns nothing about ids.
        $this->rules->requireSomewhere(self::PERMISSION);

        $this->db->transaction(function () use ($command): void {
            $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);

            foreach ($this->rules->scopesFor($this->assignments->byStaff($target->id())?->staffStores()) as $scope) {
                $this->authorizer->authorize(self::PERMISSION, $scope);
            }

            $author = $this->rules->author();
            $targetGrants = $this->grants->forStaff($target->id());
            $this->rules->requireManageable($author, $target, $targetGrants);
            // A new link to the account: it needs every action of their role (owner, 2026-09-19).
            $this->rules->requireCoversActionsOf($author, $targetGrants);

            if ($target->status() !== StaffStatus::Invited) {
                throw new InvalidStaffStatus($target->status());
            }

            $this->invitations->send($target, $author->staffId);
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.invitation_resent', $target));
        });
    }
}
