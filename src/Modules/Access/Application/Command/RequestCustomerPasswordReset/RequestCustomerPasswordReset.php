<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestCustomerPasswordReset;

/**
 * "I forgot my password", from a storefront page (spec §1.8).
 *
 * $ip is the address the form came from, counted against the store's hourly limit; empty outside a
 * request, where there is no connection to limit.
 */
final readonly class RequestCustomerPasswordReset
{
    public function __construct(
        public string $email,
        public string $ip = '',
    ) {}
}
