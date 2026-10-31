<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetStaffAuthenticator;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\NoAuthenticator;
use Modules\Access\Domain\Exception\StaffNotEditable;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * App and recovery codes both lost: any Super Admin resets that person's app from the staff page, and a
 * Super Admin's by another one (amendment 91, decision 4) - never their own, which they remove from
 * their account page with their password. Audited. Their next sign-in is by SMS, then a new setup when
 * the app is their method. Their open sessions and trusted browsers stay (my assumption (iii)).
 */
final readonly class ResetStaffAuthenticatorHandler
{
    public const string PERMISSION = AccessPermissions::STAFF_AUTHENTICATOR_RESET;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffAuthenticatorRepository $authenticators,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @throws StaffNotFound|StaffNotEditable|NoAuthenticator
     */
    public function handle(ResetStaffAuthenticator $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        if ($command->staffId === $this->rules->currentStaffId()) {
            throw new StaffNotEditable($command->staffId, StaffNotEditable::YOURSELF);
        }

        $this->db->transaction(function () use ($command): void {
            $staff = $this->staff->byId($command->staffId) ?? throw new StaffNotFound($command->staffId);

            if ($this->authenticators->forStaff($staff->id(), lock: true)?->isConfirmed() !== true) {
                throw new NoAuthenticator;
            }

            $this->authenticators->remove($staff->id());
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.authenticator_reset', $staff, ['by' => 'super_admin']));
        }, 3);
    }
}
