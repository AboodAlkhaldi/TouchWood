<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResendSuperAdminInvitation;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Staff\Invitations;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class ResendSuperAdminInvitationHandler
{
    public const string PERMISSION = AccessPermissions::SUPER_ADMIN_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private Invitations $invitations,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(ResendSuperAdminInvitation $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->rules->requireConsole(self::PERMISSION);
        $email = EmailAddress::of($command->email);

        $this->db->transaction(function () use ($email): void {
            $staff = $this->staff->byEmail($email) ?? throw new StaffNotFound($email->value);

            if (! $staff->isSuperAdmin()) {
                throw new InvalidAccessAttribute('email', 'not a Super Admin; an admin resends a staff invitation in the panel');
            }

            if ($staff->status() !== StaffStatus::Invited) {
                throw new InvalidStaffStatus($staff->status());
            }

            $this->invitations->send($staff, null);
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.invitation_resent', $staff));
        }, 3);
    }
}
