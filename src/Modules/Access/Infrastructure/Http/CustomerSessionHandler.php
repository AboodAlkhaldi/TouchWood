<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Http;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;

/**
 * The storefront's session rows carry the customer they belong to, in `user_id` (a ULID column
 * since the table was written). Laravel fills that column from its own auth guard, which this
 * application does not use, so it stays empty; here it is read from the session itself.
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
        $payload['user_id'] = self::customerIn($data);

        return $payload;
    }

    /**
     * The signed-in customer inside a serialized session, or null for a guest. Anything unreadable
     * is nobody: a session row must be written whatever it holds.
     */
    private static function customerIn(string $data): ?string
    {
        // Sessions are serialized as JSON here (config/session.php); PHP's own serialization is
        // the framework's other choice, so both are read. With SESSION_ENCRYPT on, what arrives
        // is the ciphertext of one of the two, and reading it is the only way this keeps working.
        $attributes = self::decode($data) ?? self::decode(self::decrypted($data) ?? '');

        if ($attributes === null) {
            return null;
        }

        // The session's own keys are dot paths, so what Store::put wrote is nested.
        $customer = Arr::get($attributes, LaravelCustomerSessions::SIGNED_IN);
        $id = is_array($customer) ? ($customer['id'] ?? null) : null;

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
