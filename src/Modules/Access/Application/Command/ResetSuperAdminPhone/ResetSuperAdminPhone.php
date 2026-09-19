<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetSuperAdminPhone;

/**
 * A Super Admin lost their phone (amendment 14): it is removed, and at the next sign-in, after the
 * password, they enter a new number and verify it by SMS code. Only from the server's console.
 */
final readonly class ResetSuperAdminPhone
{
    public function __construct(
        public string $email,
    ) {}
}
