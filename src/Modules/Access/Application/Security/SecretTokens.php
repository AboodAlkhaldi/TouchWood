<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

/**
 * The secrets inside links (invitations, email changes). The link carries the token; only its
 * SHA-256 hash is stored, so a copy of the database opens no link.
 */
final class SecretTokens
{
    /**
     * @return array{token: string, hash: string} 32 random bytes, base64url
     */
    public static function issue(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
