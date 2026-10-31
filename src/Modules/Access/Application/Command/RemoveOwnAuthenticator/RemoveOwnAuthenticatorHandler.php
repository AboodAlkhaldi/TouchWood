<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RemoveOwnAuthenticator;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\OwnPasswordCheck;
use Modules\Access\Application\Security\StaffSignInMethods;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Domain\Exception\AccountLocked;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\NoAuthenticator;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * One's own app removed, with its recovery codes (amendment 91), after the current password (decision
 * 6). Audited. While the app is still their method - the only box ticked - this session must set a new
 * one up before it goes on.
 */
final readonly class RemoveOwnAuthenticatorHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private OwnPasswordCheck $confirmation,
        private StaffAuthenticatorRepository $authenticators,
        private StaffSignInMethods $methods,
        private StaffSessions $sessions,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    /**
     * @return bool whether a new app must be set up now
     *
     * @throws InvalidAccessAttribute|AccountLocked|NoAuthenticator
     */
    public function handle(RemoveOwnAuthenticator $command): bool
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        $this->confirmation->confirm(
            $this->staff->find($staffId) ?? throw new StaffNotFound($staffId),
            $command->currentPassword,
            $command->ip,
        );

        $this->db->transaction(function () use ($staffId): void {
            $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);

            if (! $this->authenticators->hasConfirmedApp($staffId, lock: true)) {
                throw new NoAuthenticator;
            }

            $this->authenticators->remove($staffId);
            $this->platform->recordAudit(StaffAudit::event('access.staff_user.authenticator_removed', $staff));
        }, 3);

        $mustSetUp = $this->methods->mustSetUp($staffId);
        $this->sessions->requireAuthenticatorSetup($mustSetUp);

        return $mustSetUp;
    }
}
