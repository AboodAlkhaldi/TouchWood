<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelExpiredSuperAdminInvitations;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffCancellation;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Each one in its own transaction, checked again under its lock: someone who accepted a moment ago,
 * or was sent a new link, stays.
 */
final readonly class CancelExpiredSuperAdminInvitationsHandler
{
    public const string PERMISSION = AccessPermissions::SUPER_ADMIN_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffSecuritySettings $settings,
        private StaffCancellation $cancellation,
        private Connection $db,
    ) {}

    /**
     * @return int how many were cancelled
     */
    public function handle(CancelExpiredSuperAdminInvitations $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->rules->requireConsole(self::PERMISSION);
        $cutoff = CarbonImmutable::now()->subHours($this->settings->superAdminInvitationHours());
        $cancelled = 0;

        foreach ($this->staff->superAdminInvitationsSentBefore($cutoff) as $staffId) {
            $cancelled += $this->db->transaction(function () use ($staffId, $cutoff): int {
                $staff = $this->staff->byId($staffId);

                if ($staff === null || ! $staff->isSuperAdmin() || $staff->status() !== StaffStatus::Invited
                    || ! in_array($staffId, $this->staff->superAdminInvitationsSentBefore($cutoff), true)) {
                    return 0;
                }

                $this->cancellation->cancel($staff);

                return 1;
            }, 3);
        }

        return $cancelled;
    }
}
