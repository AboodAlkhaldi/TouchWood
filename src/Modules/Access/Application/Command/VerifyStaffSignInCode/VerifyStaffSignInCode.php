<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyStaffSignInCode;

/**
 * The second step of signing in: the SMS code, for the sign-in this browser started with the right
 * password (spec §4.4). $trustBrowser: no code on this browser for the next 30 days.
 */
final readonly class VerifyStaffSignInCode
{
    public function __construct(
        public string $code,
        public bool $trustBrowser = false,
    ) {}
}
