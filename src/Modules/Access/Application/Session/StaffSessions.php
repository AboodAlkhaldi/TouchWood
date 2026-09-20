<?php

declare(strict_types=1);

namespace Modules\Access\Application\Session;

/**
 * The admin panel's session in this browser — its own cookie, apart from the storefront's (spec
 * §1.8). Handlers read and change it only through this port; the web layer implements it.
 */
interface StaffSessions
{
    /**
     * Who is signed in, if the session is still good: not idle too long, not past its hours, signed
     * in under the account's current session version, and the account still active. A session that
     * is not good any more ends here.
     */
    public function signedIn(): ?string;

    /**
     * After the right password: who is signing in, under which session version, until the code step.
     */
    public function beginSignIn(string $staffId, int $sessionVersion, bool $needsPhone): void;

    /**
     * The sign-in this browser started with the right password, while still fresh.
     */
    public function pendingSignIn(): ?PendingSignIn;

    /**
     * Signed in: a new session id (spec §1.8), their session version, and the request acts as them.
     */
    public function start(string $staffId, int $sessionVersion): void;

    /**
     * Their own password changed here: this session stays, every other one ends.
     */
    public function keep(int $sessionVersion): void;

    /**
     * Signed out: the session ends and the request acts as a guest again.
     */
    public function end(): void;
}
