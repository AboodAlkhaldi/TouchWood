<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

/**
 * The panel's session rows carry the staff member they belong to, in `user_id`.
 *
 * The storefront has had this since 2026-09-21 ({@see CustomerSessionHandler}); the panel never
 * did, because until now nothing asked a session row who owned it. Laravel fills that column from
 * its own auth guard, which this application does not use, so every admin row was written with it
 * empty — which is why a staff member could not be shown their own sessions, and why signing them
 * all out had nothing to delete by.
 *
 * Reading it from the session itself is the same trick, for the same reason, on the other side.
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
        $payload['user_id'] = self::staffIn($data);

        return $payload;
    }

    /**
     * The signed-in staff member inside a serialized session, or null.
     *
     * Null covers a browser part-way through signing in — it has a session before it has a person —
     * and anything unreadable: a session row must be written whatever it holds.
     */
    private static function staffIn(string $data): ?string
    {
        // Sessions are serialized as JSON here (config/session.php); PHP's own serialization is the
        // framework's other choice, so both are read. With SESSION_ENCRYPT on, what arrives is the
        // ciphertext of one of the two, and reading it is the only way this keeps working.
        $attributes = self::decode($data) ?? self::decode(self::decrypted($data) ?? '');

        if ($attributes === null) {
            return null;
        }

        // The session's own keys are dot paths, so what Store::put wrote is nested.
        $staff = Arr::get($attributes, LaravelStaffSessions::SIGNED_IN);
        $id = is_array($staff) ? ($staff['id'] ?? null) : null;

        return is_string($id) ? $id : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(string $data): ?array
    {
        if ($data === '') {
            return null;
        }

        $attributes = json_decode($data, true);

        if (! is_array($attributes)) {
            $attributes = @unserialize($data, ['allowed_classes' => false]);
        }

        return is_array($attributes) ? $attributes : null;
    }

    /**
     * What an encrypted session holds, when this application encrypts them.
     */
    private static function decrypted(string $data): ?string
    {
        try {
            // EncryptedStore encrypts with the serialization on, as Crypt::decrypt expects.
            $plain = Crypt::decrypt($data);
        } catch (DecryptException) {
            return null;
        }

        return is_string($plain) ? $plain : null;
    }
}
