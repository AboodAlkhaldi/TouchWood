<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\DeleteCustomerOnRequest;

/**
 * The customer asked support to delete their account (spec §3.3, amendment 43): an admin-only
 * action, and the reason is what the customer said.
 */
final readonly class DeleteCustomerOnRequest
{
    public function __construct(
        public string $customerId,
        public string $reason,
    ) {}
}
