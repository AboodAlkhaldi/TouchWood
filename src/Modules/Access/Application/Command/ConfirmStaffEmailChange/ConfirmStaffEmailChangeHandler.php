<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ConfirmStaffEmailChange;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Command\ChangeStaffEmail\ChangeStaffEmailHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Whoever holds the link — it was sent only to the new address — the same permission as accepting
 * an invitation: every guest, with a staff link (spec §3.2).
 */
final readonly class ConfirmStaffEmailChangeHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_ACCEPT_INVITATION;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private RoleAssignmentRepository $assignments,
        private StaffTokenRepository $tokens,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(ConfirmStaffEmailChange $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $this->db->transaction(function () use ($command): void {
            $change = $this->tokens->emailChangeByToken(SecretTokens::hash($command->token));

            if ($change === null || $change->isExpired(CarbonImmutable::now())) {
                throw new InvalidOrExpiredLink;
            }

            $staff = $this->staff->byId($change->staffId) ?? throw new InvalidOrExpiredLink;

            if ($staff->status() !== StaffStatus::Active) {
                throw new InvalidOrExpiredLink;
            }

            // The change takes effect now, so whoever asked for it must still be allowed to make it:
            // they may since have lost their role, or the person become an admin or a Super Admin.
            $askedByAnother = $change->requestedBy !== null && $change->requestedBy !== $staff->id();

            if ($askedByAnother && ! $this->rules->mayStillManage((string) $change->requestedBy, ChangeStaffEmailHandler::PERMISSION, $staff, $this->assignments->byStaff($staff->id())?->staffStores())) {
                throw new InvalidOrExpiredLink;
            }

            // Another account may have taken the address since the link was sent.
            if ($this->staff->emailInUse($change->newEmail, $staff->id())) {
                throw new StaffEmailInUse;
            }

            $before = clone $staff;
            $staff->changeEmail($change->newEmail);
            $this->staff->update($staff);
            $this->tokens->deleteEmailChange($staff->id());
            // A reset link mailed to the old address dies with it (review of step 3b).
            $this->tokens->deletePasswordReset($staff->id());
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.email_changed', $before, $staff, $staff->pullChanges()));
        }, 3);
    }
}
