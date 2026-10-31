<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SetOwnCriticalSms;

use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Switches the person's own critical SMS on or off (amendment 81; ops.md §1.6): the payment
 * gateway's key expiring in 1 day, or refused. Only a Super Admin is sent that SMS, so only a Super
 * Admin sees the switch and may change it; anyone else is refused. A change is audited in the same
 * transaction, under a lock on the person's row; the same value again changes and records nothing.
 */
final readonly class SetOwnCriticalSmsHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private NotificationPreferenceRepository $preferences,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /** @throws Unauthorized */
    public function handle(SetOwnCriticalSms $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        $this->db->transaction(function () use ($staffId, $command): void {
            // The row is locked until the end (byId), so two identical requests queue up: the second
            // reads what the first wrote, and only one change is audited.
            if ($this->staff->byId($staffId)?->isSuperAdmin() !== true) {
                throw new Unauthorized(self::PERMISSION);
            }

            $was = $this->preferences->criticalSms($staffId);

            if ($was === $command->on) {
                return;
            }

            $this->preferences->setCriticalSms($staffId, $command->on);
            $this->platform->recordAudit(new AuditEntryDto(
                'access.staff_user.critical_sms_changed',
                'access.staff_user',
                $staffId,
                null,
                AuditChanges::none()->changed('critical_sms', $was, $command->on),
            ));
        });
    }
}
