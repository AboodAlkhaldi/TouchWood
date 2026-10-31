<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyStaffSignInAppCode;

/**
 * The second step of signing in for someone whose method is the authenticator app (amendment 91): a
 * code from the app, or - $recovery - one of their recovery codes. $trustBrowser: no second step on
 * this browser for the next 12 hours (a setting), as after an SMS code.
 */
final readonly class VerifyStaffSignInAppCode
{
    public function __construct(
        #[\SensitiveParameter]
        public string $code,
        public bool $recovery = false,
        public bool $trustBrowser = false,
    ) {}
}
