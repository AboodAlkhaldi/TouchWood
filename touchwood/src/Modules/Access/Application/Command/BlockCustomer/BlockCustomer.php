<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\BlockCustomer;

/**
 * Staff block a customer (spec §3.3): they cannot sign in, and their open sessions end at once.
 */
final readonly class BlockCustomer
{
    public function __construct(
        public string $customerId,
        public string $reason,
    ) {}
}
