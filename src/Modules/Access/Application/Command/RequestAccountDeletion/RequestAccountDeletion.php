<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestAccountDeletion;

/**
 * "Delete my account" (spec §1.10): the customer confirms with their password, as they do for any
 * change to their own credentials. A wrong password counts towards the sign-in lockout, from $ip.
 */
final readonly class RequestAccountDeletion
{
    public function __construct(
        public string $password,
        public string $ip,
    ) {}
}
