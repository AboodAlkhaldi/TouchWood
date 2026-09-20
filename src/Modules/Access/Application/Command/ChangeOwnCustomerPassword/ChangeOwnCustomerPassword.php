<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeOwnCustomerPassword;

/**
 * A customer's own new password: this session stays, every other one ends (spec §1.8). A wrong
 * current password counts towards the sign-in lockout, from $ip.
 */
final readonly class ChangeOwnCustomerPassword
{
    public function __construct(
        public string $currentPassword,
        public string $newPassword,
        public string $ip,
    ) {}
}
