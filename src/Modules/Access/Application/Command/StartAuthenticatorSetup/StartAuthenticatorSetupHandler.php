<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\StartAuthenticatorSetup;

use Carbon\CarbonImmutable;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\AuthenticatorCodes;
use Modules\Access\Application\Security\OwnPasswordCheck;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Domain\Exception\AccountLocked;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The password before the QR code (amendment 91, decision 6), counted like a wrong one at sign-in. A
 * new secret then waits for its first code for 15 minutes, shown only in this browser; the app in use,
 * if there is one, stays until the new one's code is right. Not audited: the setup is, once confirmed.
 */
final readonly class StartAuthenticatorSetupHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private OwnPasswordCheck $confirmation,
        private StaffAuthenticatorRepository $authenticators,
        private AuthenticatorCodes $codes,
        private StaffSessions $sessions,
    ) {}

    /**
     * @throws InvalidAccessAttribute|AccountLocked when it is not the current password
     */
    public function handle(StartAuthenticatorSetup $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        $this->confirmation->confirm(
            $this->staff->find($staffId) ?? throw new StaffNotFound($staffId),
            $command->currentPassword,
            $command->ip,
        );

        $now = CarbonImmutable::now();
        $this->authenticators->startSetup($staffId, $this->codes->newSecret(), $now);
        $this->sessions->openAuthenticatorSetup($now->getTimestamp());
    }
}
