<?php

declare(strict_types=1);

namespace Modules\Access\Application\Session;

use Modules\Access\Application\Security\StaffSignInMethods;

/**
 * Whether the signed-in session must set up an authenticator app before it reaches the panel (amendment
 * 91), asked on every panel request (owner, 2026-10-31: sessions already open at go-live are sent to the
 * setup at once, not at their next sign-in).
 *
 * The answer is kept in the session with the two boxes it was given under, and asked again only when
 * they change: whatever else decides it - an app confirmed, chosen or removed - is done through this
 * session (which records it) or ends every session (a reset), so a warm request reads nothing more.
 * Choosing the app with none set up in one browser, while both boxes are on, gates only that browser.
 */
final readonly class AuthenticatorGate
{
    public function __construct(
        private StaffSessions $sessions,
        private StaffSignInMethods $methods,
    ) {}

    public function mustSetUp(string $staffId): bool
    {
        // Only a signed-in session is sent anywhere; every staff request has one.
        if (! $this->sessions->holdsSignIn()) {
            return false;
        }

        $under = $this->methods->key();

        if ($this->sessions->authenticatorCheckedUnder() === $under) {
            return $this->sessions->mustSetUpAuthenticator();
        }

        // New boxes, or a session not checked yet (open before go-live): asked afresh.
        $mustSetUp = $this->methods->mustSetUp($staffId);
        $this->sessions->rememberAuthenticatorCheck($under, $mustSetUp);

        return $mustSetUp;
    }
}
