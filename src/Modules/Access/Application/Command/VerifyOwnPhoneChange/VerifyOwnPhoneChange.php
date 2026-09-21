<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\VerifyOwnPhoneChange;

/**
 * The code sent to the staff member's new phone: when it is right, the new number replaces the old.
 */
final readonly class VerifyOwnPhoneChange
{
    public function __construct(
        public string $code,
    ) {}
}
