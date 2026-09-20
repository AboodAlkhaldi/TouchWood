<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetStaffPassword;

/**
 * A new password through the emailed link. Every session and trusted browser ends, so the next
 * sign-in asks the SMS code (spec §1.8).
 */
final readonly class ResetStaffPassword
{
    public function __construct(
        public string $token,
        public string $password,
    ) {}
}
