<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * The storefront's session rows carry the customer they belong to, in `user_id` (a ULID column
 * since the table was written). Laravel fills that column from its own auth guard, which this
 * application does not use, so it stays empty; here it is read from the session itself
 * ({@see SessionOwner}).
 *
 * It is what lets a deleted account take its sessions with it: anonymizing removes the rows of the
 * person it emptied, so no id, address or browser of theirs is left behind for up to a year (owner,
 * 2026-09-21). A guest's row keeps no id at all.
 */
final class CustomerSessionHandler extends DatabaseSessionHandler
{
    /**
     * @param  string  $data  the session's own serialized attributes
     * @return array<string, mixed>
     */
    protected function getDefaultPayload($data): array
    {
        $payload = parent::getDefaultPayload($data);
        $payload['user_id'] = SessionOwner::in($data, LaravelCustomerSessions::SIGNED_IN);

        return $payload;
    }
}
