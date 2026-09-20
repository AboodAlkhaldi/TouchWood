<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelSuperAdminInvitation;

use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Staff\StaffCancellation;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\InvalidStaffStatus;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class CancelSuperAdminInvitationHandler
{
    public const string PERMISSION = AccessPermissions::SUPER_ADMIN_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffCancellation $cancellation,
        private Connection $db,
    ) {}

    public function handle(CancelSuperAdminInvitation $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->rules->requireConsole(self::PERMISSION);
        $email = EmailAddress::of($command->email);

        $this->db->transaction(function () use ($email): void {
            $staff = $this->staff->byEmail($email) ?? throw new StaffNotFound($email->value);

            if (! $staff->isSuperAdmin()) {
                throw new InvalidAccessAttribute('email', 'not a Super Admin; an admin cancels a staff invitation in the panel');
            }

            if ($staff->status() !== StaffStatus::Invited) {
                throw new InvalidStaffStatus($staff->status());
            }

            $this->cancellation->cancel($staff);
        }, 3);
    }
}
