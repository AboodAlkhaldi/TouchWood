<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Security;

use DateTimeImmutable;
use Modules\Access\Application\Security\AuthenticatorCodes;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP through pragmarx/google2fa (MIT; amendment 91): its defaults are what every authenticator app
 * reads - SHA-1, 6 digits, 30 seconds. The time is ours (Carbon's "now"), passed in, never the
 * library's own clock.
 */
final readonly class Google2faAuthenticatorCodes implements AuthenticatorCodes
{
    /** One step either side: a phone's clock out by up to 30 seconds still signs in. */
    private const int WINDOW = 1;

    private Google2FA $totp;

    /**
     * @param  string  $issuer  the name the app shows the account under (APP_NAME)
     */
    public function __construct(
        private string $issuer,
    ) {
        $this->totp = new Google2FA;
    }

    public function newSecret(): string
    {
        // 32 base32 characters: 160 bits, the length RFC 4226 recommends for an HMAC-SHA1 key.
        return $this->totp->generateSecretKey(32);
    }

    public function uri(string $holder, #[\SensitiveParameter] string $secret): string
    {
        return $this->totp->getQRCodeUrl($this->issuer, $holder, $secret);
    }

    public function verify(#[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $code, ?int $lastUsedStep, DateTimeImmutable $now): ?int
    {
        $code = trim($code);

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        $step = intdiv($now->getTimestamp(), $this->totp->getKeyRegeneration());
        // With an old step given, the library answers with the step the code matched, never one at or
        // before it - so a code is never taken twice. 0 stands for "none yet".
        $matched = $this->totp->verifyKeyNewer($secret, $code, $lastUsedStep ?? 0, self::WINDOW, $step);

        return is_int($matched) ? $matched : null;
    }
}
