<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetSuperAdminPhone;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The phone goes, and so does every trusted browser (amendment 14): the next sign-in asks for a new
 * number after the password.
 */
final readonly class ResetSuperAdminPhoneHandler
{
    public const string PERMISSION = AccessPermissions::SUPER_ADMIN_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffTokenRepository $tokens,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(ResetSuperAdminPhone $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->rules->requireConsole(self::PERMISSION);
        $email = EmailAddress::of($command->email);

        $this->db->transaction(function () use ($email): void {
            $staff = $this->staff->byEmail($email) ?? throw new StaffNotFound($email->value);

            if (! $staff->isSuperAdmin()) {
                throw new InvalidAccessAttribute('email', 'not a Super Admin; an admin changes a staff phone in the panel');
            }

            $before = clone $staff;
            $staff->resetPhone();
            $this->staff->update($staff);
            $this->tokens->deletePhoneCode($staff->id());
            $this->tokens->forgetTrustedBrowsers($staff->id());
            $this->platform->recordAudit(StaffAudit::updated('access.staff_user.phone_reset', $before, $staff, $staff->pullChanges()));
        }, 3);
    }
}
