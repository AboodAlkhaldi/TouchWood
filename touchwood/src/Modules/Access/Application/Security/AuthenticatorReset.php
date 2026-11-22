<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use Modules\Access\Application\Audit\StaffAudit;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Session\StaffSessionDirectory;
use Modules\Access\Application\StaffView\StaffViews;
use Modules\Access\Domain\Exception\NoAuthenticator;
use Modules\Access\Domain\Model\StaffUser;
use Modules\Access\Domain\Repository\StaffAuthenticatorRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * Someone's lost authenticator app reset, by a Super Admin or the console (amendment 91, decision 4):
 * the app and its recovery codes go, and - owner, 2026-10-31 - the person is signed out everywhere and
 * every browser they trusted is forgotten, as "sign out everywhere" does. Audited. Call inside the
 * change's transaction.
 */
final readonly class AuthenticatorReset
{
    public function __construct(
        private StaffUserRepository $staff,
        private StaffAuthenticatorRepository $authenticators,
        private StaffTokenRepository $tokens,
        private StaffSessionDirectory $sessions,
        private StaffViews $views,
        private GrantsReader $grants,
        private PlatformApi $platform,
    ) {}

    /**
     * @param  string  $by  "super_admin" or "console", for the audit entry
     *
     * @throws NoAuthenticator
     */
    public function reset(StaffUser $staff, string $by): void
    {
        if (! $this->authenticators->hasConfirmedApp($staff->id(), lock: true)) {
            throw new NoAuthenticator;
        }

        $this->authenticators->remove($staff->id());

        // Every session holding the old number is refused on its next request, wherever it is (a disabled
        // account has none left to end).
        if ($staff->status() === StaffStatus::Active) {
            $staff->endEverySession();
            $this->staff->update($staff);
            $staff->pullChanges();
        }

        $this->tokens->forgetTrustedBrowsers($staff->id());
        $this->sessions->endAll($staff->id());
        $this->views->endAllOf($staff->id());
        // The number sessions are checked against is the cached one (SignOutEverywhereHandler).
        $this->grants->refresh($staff->id());

        $this->platform->recordAudit(StaffAudit::event('access.staff_user.authenticator_reset', $staff, ['by' => $by]));
    }
}
