<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\CancelCustomerDeletion;

/**
 * The customer asked support to stop the deletion (amendment 43) — they may not be able to sign in,
 * which is the other way to stop it.
 */
final readonly class CancelCustomerDeletion
{
    public function __construct(
        public string $customerId,
        public string $reason,
    ) {}
}
