<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

/**
 * Who a serialized session belongs to, read from the session itself.
 *
 * Both of the application's session tables keep the person in `user_id`, and Laravel fills that
 * column from its own auth guard, which this application does not use. So the two session drivers
 * — {@see CustomerSessionHandler} for the shop, {@see StaffSessionHandler} for the panel — each
 * read their person out of the data they are about to write. One reading, in one place: the
 * fallbacks below are subtle enough that two copies would drift.
 */
final class SessionOwner
{
    /**
     * The id stored under $key in a serialized session, or null.
     *
     * Null covers a guest, a browser part-way through signing in — it has a session before it has
     * a person — and anything unreadable: a session row must be written whatever it holds.
     *
     * @param  string  $key  where the signed-in person lives in the session, e.g. LaravelStaffSessions::SIGNED_IN
     */
    public static function in(string $data, string $key): ?string
    {
        // Sessions are serialized as JSON here (config/session.php); PHP's own serialization is the
        // framework's other choice, so both are read. With SESSION_ENCRYPT on, what arrives is the
        // ciphertext of one of the two, and reading it is the only way this keeps working.
        $attributes = self::decode($data) ?? self::decode(self::decrypted($data) ?? '');

        if ($attributes === null) {
            return null;
        }

        // The session's own keys are dot paths, so what Store::put wrote is nested.
        $person = Arr::get($attributes, $key);
        $id = is_array($person) ? ($person['id'] ?? null) : null;

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
