<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * The panel's session rows carry the staff member they belong to, in `user_id`.
 *
 * The storefront has had this since 2026-09-21 ({@see CustomerSessionHandler}); the panel never
 * did, because until now nothing asked a session row who owned it. Laravel fills that column from
 * its own auth guard, which this application does not use, so every admin row was written with it
 * empty — which is why a staff member could not be shown their own sessions, and why signing them
 * all out had nothing to delete by.
 *
 * Reading it from the session itself is the same trick, for the same reason, on the other side
 * ({@see SessionOwner}).
 */
final class StaffSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  string  $data  the session's own serialized attributes
     * @return array<string, mixed>
     */
    protected function getDefaultPayload($data): array
    {
        $payload = parent::getDefaultPayload($data);
        $payload['user_id'] = SessionOwner::in($data, LaravelStaffSessions::SIGNED_IN);

        return $payload;
    }
}
