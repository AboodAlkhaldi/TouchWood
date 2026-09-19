<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Another account already uses this phone: refused when the number is entered, not after the code
 * (spec §1.3). Among staff, a phone is unique (owner's decision, 2026-09-19).
 */
final class PhoneAlreadyInUse extends AccessError
{
    public function __construct()
    {
        parent::__construct('Another account already uses this phone number.');
    }

    public function type(): string
    {
        return 'access.phone_already_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
