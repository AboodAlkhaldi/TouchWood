<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The email already belongs to a customer account. Told plainly — "You already have an account —
 * please sign in" (spec §1.2, owner's decision 2026-09-19) — because the person is not an attacker
 * guessing addresses: they are on the registration form with their own.
 */
final class EmailAlreadyRegistered extends AccessError
{
    public function __construct()
    {
        parent::__construct('An account already has this email address.');
    }

    public function type(): string
    {
        return 'access.email_already_registered';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
