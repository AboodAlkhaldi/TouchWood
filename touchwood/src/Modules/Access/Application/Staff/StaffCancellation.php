<?php

declare(strict_types=1);

namespace Modules\Access\Application\Staff;

use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * Cancelling an invited person's account (amendment 29): final. Every link and code dies, and the
 * role goes — nobody holds a role for an account that can never work — so the email and phone are
 * free for a new account. The row stays, for the audit log. Call inside the caller's transaction,
 * after its own checks, with the staff member locked.
 */
final readonly class StaffCancellation
{
    public function __construct(
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private RoleAssignmentRepository $assignments,
        private RoleRepository $roles,
        private GrantsReader $grants,
        private PlatformApi $platform,
    ) {}

    public function cancel(StaffUser $target): void
    {
        $before = clone $target;
        $target->cancel();
        $this->staff->update($target);

        $this->tokens->deleteInvitation($target->id());
        $this->tokens->deletePhoneCode($target->id());
        $this->tokens->deleteEmailChange($target->id());

        $assignment = $this->assignments->byStaff($target->id());

        if ($assignment !== null) {
            $this->assignments->delete($target->id());
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.role_removed', $target, ['role_id' => $assignment->roleId()]));
        }

        $personal = $this->roles->personalRoleOf($target->id());

        if ($personal !== null) {
            $this->roles->delete($personal->id());
            $this->platform->recordAudit(RoleAudit::deleted($personal, null, []));
        }

        $this->platform->recordAudit(StaffAudit::updated('access.staff_user.cancelled', $before, $target, $target->pullChanges()));
        $this->grants->refresh($target->id());
    }
}
