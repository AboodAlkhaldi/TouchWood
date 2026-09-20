<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestCustomerPasswordReset;

/**
 * "I forgot my password", from a storefront page (spec §1.8).
 */
final readonly class RequestCustomerPasswordReset
{
    public function __construct(
        public string $email,
    ) {}
}
