<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ResetCustomerPassword;

/**
 * The new password, chosen from the link the customer received (spec §1.8).
 */
final readonly class ResetCustomerPassword
{
    public function __construct(
        public string $token,
        public string $password,
    ) {}
}
