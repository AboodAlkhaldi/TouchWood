<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelStaffInvitation;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;

final readonly class CancelStaffInvitationHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_INVITE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private GrantsReader $grants,
        private StaffTokenRepository $tokens,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(CancelStaffInvitation $command): void
    {
        $this->rules->requireSomewhere(self::PERMISSION);

        $this->db->transaction(function () use ($command): void {
            $target = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);

            foreach ($this->rules->scopesFor($this->assignments->byStaff($target->id())?->staffStores()) as $scope) {
                $this->authorizer->authorize(self::PERMISSION, $scope);
            }

            $this->rules->requireManageable($this->rules->author(), $target, $this->grants->forStaff($target->id()));

            if ($target->status() !== StaffStatus::Invited) {
                throw new InvalidStaffStatus($target->status());
            }

            $this->tokens->deleteInvitation($target->id());
            $this->tokens->deletePhoneCode($target->id());
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.invitation_cancelled', $target));
        }, 3);
    }
}
