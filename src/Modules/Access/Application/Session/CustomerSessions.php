<?php

declare(strict_types=1);

namespace Modules\Access\Application\Session;

/**
 * The storefront session in this browser — the site's own cookie, apart from the admin panel's
 * (spec §1.8). Handlers read and change it only through this port; the web layer implements it.
 */
interface CustomerSessions
{
    /**
     * Who is signed in, if the session is still good: not idle too long (or remembered), signed in
     * under the account's current password, and the account not blocked. A session that is not good
     * any more ends here.
     */
    public function signedIn(): ?string;

    /**
     * Signed in: a new session id (spec §1.8), their session version, and the request acts as them.
     *
     * @param  bool  $remember  "remember me": they stay signed in for the store's remembered days,
     *                          with no idle limit; otherwise the idle limit applies
     */
    public function start(string $customerId, int $sessionVersion, bool $remember): void;

    /**
     * Their own password changed here: this session stays, every other one ends.
     */
    public function keep(int $sessionVersion): void;

    /**
     * Signed out: the session ends and the request acts as a guest again.
     */
    public function end(): void;

    /**
     * Every session this account has, anywhere, is removed — not only this browser's.
     * Anonymizing a deleted account calls it, so nothing of the person is left in a session
     * row (owner, 2026-09-21). Raising the session version already makes them useless; this
     * takes the rows themselves, with the id, address and browser they hold.
     */
    public function endEveryDeviceOf(string $customerId): void;
}
