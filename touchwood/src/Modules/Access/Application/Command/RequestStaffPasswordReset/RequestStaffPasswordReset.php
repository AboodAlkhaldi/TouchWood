<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestStaffPasswordReset;

/**
 * "Forgot your password?" — the answer is always the same, whether or not the email has an
 * account, so it tells a stranger nothing.
 */
final readonly class RequestStaffPasswordReset
{
    public function __construct(
        public string $email,
    ) {}
}
