<?php

declare(strict_types=1);

namespace Modules\Access\Application\Session;

/**
 * The panel's live session rows, by the staff member they belong to (spec §1.8).
 *
 * A session is not a domain object — it is a row Laravel writes and sweeps — so this is a small
 * contract over that table rather than a repository of anything. It exists because a staff member
 * may look at their own sessions and end them (owner, 2026-09-26), and because ending them means
 * deleting rows rather than waiting for each browser's next request.
 *
 * Every method takes the staff id as well as the session id: **nobody ends anybody else's**, and
 * the check belongs in the query, not in a handler that might forget it.
 */
interface StaffSessionDirectory
{
    /**
     * Their live sessions, most recently seen first.
     *
     * @return list<array{id: string, ip_address: string|null, user_agent: string|null, last_activity: int}>
     */
    public function forStaff(string $staffId): array;

    /** One of their sessions, gone at once rather than on its next request. */
    public function end(string $sessionId, string $staffId): void;

    /** Every session of theirs, including the one asking. */
    public function endAll(string $staffId): void;
}
