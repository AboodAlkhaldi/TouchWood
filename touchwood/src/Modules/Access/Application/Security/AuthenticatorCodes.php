<?php

declare(strict_types=1);

namespace Modules\Access\Application\Security;

use DateTimeImmutable;

/**
 * Authenticator app codes (amendment 91): TOTP as every app reads it - 6 digits, a new one every 30
 * seconds - with one step either side for a phone's clock out by up to 30 seconds.
 */
interface AuthenticatorCodes
{
    /**
     * A new secret, in the base32 an app is given.
     */
    public function newSecret(): string;

    /**
     * What the QR code holds: the otpauth address naming the panel and the person.
     */
    public function uri(string $holder, string $secret): string;

    /**
     * The 30-second step the code belongs to, or null when it is wrong - or belongs to $lastUsedStep or
     * an earlier step, which was already used.
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep, DateTimeImmutable $now): ?int;
}
