<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\Model\StaffAuthenticator;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\ValueObject\SignInMethod;

/**
 * Which second step a staff member takes (amendment 91, owner 2026-10-31): with one box ticked,
 * everyone uses it; with both, each person's own choice - the app when they have set it up and not
 * chosen SMS, otherwise SMS (my assumption (i), stated for the owner to reject).
 */
final readonly class StaffSignInMethods
{
    public function __construct(
        private StaffSecuritySettings $settings,
        private StaffAuthenticatorRepository $authenticators,
    ) {}

    /**
     * @return array{sms: bool, authenticator: bool}
     */
    public function enabled(): array
    {
        $sms = $this->settings->signInBySms();
        $authenticator = $this->settings->signInByAuthenticator();

        // The guard keeps one ticked (SignInMethodsGuard); were both ever off, the go-live default
        // holds rather than nobody being able to sign in.
        return $sms || $authenticator ? ['sms' => $sms, 'authenticator' => $authenticator] : ['sms' => false, 'authenticator' => true];
    }

    public function bothEnabled(): bool
    {
        $enabled = $this->enabled();

        return $enabled['sms'] && $enabled['authenticator'];
    }

    public function for(string $staffId, ?StaffAuthenticator $app = null): SignInMethod
    {
        $enabled = $this->enabled();

        if (! $enabled['authenticator']) {
            return SignInMethod::Sms;
        }

        if (! $enabled['sms']) {
            return SignInMethod::Authenticator;
        }

        $app ??= $this->authenticators->forStaff($staffId);

        return $app !== null && $app->isConfirmed() && $app->inUse ? SignInMethod::Authenticator : SignInMethod::Sms;
    }

    /**
     * Whether the app is their method and none is set up: they set it up right after signing in,
     * before they reach the panel (decision 2).
     */
    public function mustSetUp(string $staffId): bool
    {
        $app = $this->authenticators->forStaff($staffId);

        return $this->for($staffId, $app) === SignInMethod::Authenticator && ($app === null || ! $app->isConfirmed());
    }
}
