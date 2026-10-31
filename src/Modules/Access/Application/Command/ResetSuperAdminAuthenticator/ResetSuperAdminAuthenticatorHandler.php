<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetSuperAdminAuthenticator;

use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\AuthenticatorReset;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\ValueObject\EmailAddress;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * `access:super-admin:reset-authenticator {email}` (amendment 91, decision 4): a lone Super Admin who
 * lost the app and the recovery codes has nobody to reset it from the staff page, so the server does.
 * They are signed out everywhere and their trusted browsers forgotten (owner, 2026-10-31); they then
 * sign in by SMS and, when the app is their method, set a new one up. Audited.
 */
final readonly class ResetSuperAdminAuthenticatorHandler
{
    public const string PERMISSION = AccessPermissions::SUPER_ADMIN_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private AuthenticatorReset $reset,
        private Connection $db,
    ) {}

    public function handle(ResetSuperAdminAuthenticator $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $this->rules->requireConsole(self::PERMISSION);
        $email = EmailAddress::of($command->email);

        $this->db->transaction(function () use ($email): void {
            $staff = $this->staff->byEmail($email) ?? throw new StaffNotFound($email->value);

            if (! $staff->isSuperAdmin()) {
                throw new InvalidAccessAttribute('email', 'not a Super Admin; a Super Admin resets a staff member\'s app in the panel');
            }

            $this->reset->reset($staff, 'console');
        }, 3);
    }
}
