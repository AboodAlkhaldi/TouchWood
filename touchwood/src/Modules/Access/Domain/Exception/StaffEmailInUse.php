<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Another account already uses this email: one email, one account (owner's decision, 2026-09-19).
 */
final class StaffEmailInUse extends AccessError
{
    public function __construct()
    {
        parent::__construct('Another account already uses this email.');
    }

    public function type(): string
    {
        return 'access.staff_email_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
