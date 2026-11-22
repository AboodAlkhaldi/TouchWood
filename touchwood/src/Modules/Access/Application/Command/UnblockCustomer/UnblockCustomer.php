<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UnblockCustomer;

/**
 * Staff let a customer back in (spec §3.3).
 */
final readonly class UnblockCustomer
{
    public function __construct(
        public string $customerId,
        public string $reason,
    ) {}
}
