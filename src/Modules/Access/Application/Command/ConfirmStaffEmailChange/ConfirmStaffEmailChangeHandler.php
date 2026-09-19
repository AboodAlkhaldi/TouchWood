<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ConfirmStaffEmailChange;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\SecretTokens;
use Modules\Access\Domain\Exception\InvalidOrExpiredLink;
use Modules\Access\Domain\Exception\StaffEmailInUse;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
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
        private StaffUserRepository $staff,
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

            // Another account may have taken the address since the link was sent.
            if ($this->staff->emailInUse($change->newEmail, $staff->id())) {
                throw new StaffEmailInUse;
            }

            $before = clone $staff;
            $staff->changeEmail($change->newEmail);
            $this->staff->update($staff);
            $this->tokens->deleteEmailChange($staff->id());
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.email_changed', $before, $staff, $staff->pullChanges()));
        });
    }
}
