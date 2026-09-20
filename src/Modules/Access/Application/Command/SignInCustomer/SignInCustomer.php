<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\SignInCustomer;

/**
 * A customer signing in from a storefront page (spec §1.8): email and password only.
 */
final readonly class SignInCustomer
{
    public function __construct(
        public string $email,
        public string $password,
        public string $ip,
        public bool $remember = false,
    ) {}
}
