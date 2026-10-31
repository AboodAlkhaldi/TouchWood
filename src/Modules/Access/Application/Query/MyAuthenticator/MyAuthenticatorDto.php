<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyAuthenticator;

use DateTimeImmutable;

/**
 * A staff member's own authenticator app, for their account page (amendment 91).
 */
final readonly class MyAuthenticatorDto
{
    public function __construct(
        /** Which methods the Super Admins' setting has ticked. */
        public bool $smsEnabled,
        public bool $authenticatorEnabled,
        public bool $hasApp,
        public ?DateTimeImmutable $confirmedAt,
        /** "SMS" or "AUTHENTICATOR": the second step they take now. */
        public string $method,
        public int $recoveryCodesLeft,
    ) {}
}
