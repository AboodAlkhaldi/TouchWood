<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAuthenticator;

use Carbon\CarbonImmutable;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Command\ConfirmAuthenticatorSetup\ConfirmAuthenticatorSetupHandler;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Security\AuthenticatorCodes;
use Modules\Access\Application\Security\QrCodes;
use Modules\Access\Application\Security\StaffSignInMethods;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Exception\StaffNotFound;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * A staff member's own authenticator app (amendment 91): its state for the account page, and the
 * setup page's QR code - only in the browser that gave the password for it, while it is good.
 */
final readonly class MyAuthenticatorForStaff
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private StaffUserRepository $staff,
        private StaffAuthenticatorRepository $authenticators,
        private StaffSignInMethods $methods,
        private StaffSecuritySettings $settings,
        private StaffSessions $sessions,
        private AuthenticatorCodes $codes,
        private QrCodes $qr,
    ) {}

    public function forCurrentStaff(): MyAuthenticatorDto
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $app = $this->authenticators->forStaff($staffId);
        $enabled = $this->methods->enabled();
        $hasApp = $app !== null && $app->isConfirmed();

        return new MyAuthenticatorDto(
            $enabled['sms'],
            $enabled['authenticator'],
            $hasApp,
            $hasApp ? $app->confirmedAt : null,
            $this->methods->for($staffId, $app)->value,
            $hasApp ? $this->authenticators->recoveryCodesLeft($staffId) : 0,
        );
    }

    public function setupForCurrentStaff(): AuthenticatorSetupDto
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();
        $staff = $this->staff->byId($staffId) ?? throw new StaffNotFound($staffId);
        $app = $this->authenticators->forStaff($staffId);
        $openedHere = $this->sessions->authenticatorSetupOpenedAt();

        $showing = $app !== null && $app->pendingSecret !== null && $app->pendingAt !== null
            && $openedHere === $app->pendingAt->getTimestamp()
            && $app->hasPendingSince(CarbonImmutable::now(), ConfirmAuthenticatorSetupHandler::MINUTES)
            && $app->pendingAttempts < $this->settings->codeAttempts();
        $secret = $showing ? $app->pendingSecret : null;

        return new AuthenticatorSetupDto(
            $app !== null && $app->isConfirmed(),
            $this->sessions->mustSetUpAuthenticator(),
            $secret === null ? null : $this->qr->svg($this->codes->uri($staff->email()->value, $secret)),
            $secret === null ? null : implode(' ', str_split($secret, 4)),
        );
    }
}
