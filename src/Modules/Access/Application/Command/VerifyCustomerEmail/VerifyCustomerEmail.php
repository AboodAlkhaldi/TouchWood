<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyCustomerEmail;

/**
 * The customer whose verification link was opened. The link itself is the proof: the web layer
 * checks its signature and its expiry before this runs (amendment 38).
 */
final readonly class VerifyCustomerEmail
{
    public function __construct(
        public string $customerId,
    ) {}
}
