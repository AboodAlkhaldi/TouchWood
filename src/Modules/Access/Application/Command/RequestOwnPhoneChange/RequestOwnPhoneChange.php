<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RequestOwnPhoneChange;

/**
 * A staff member's own new phone: a code goes to it, and the old number stays in use until the
 * code is entered (spec §1.4). Sending it again asks for a new code.
 */
final readonly class RequestOwnPhoneChange
{
    public function __construct(
        public string $phone,
    ) {}
}
