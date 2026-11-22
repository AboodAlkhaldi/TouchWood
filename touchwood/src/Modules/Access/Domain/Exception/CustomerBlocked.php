<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The password was right, but the account is blocked. Told plainly — "Your account is blocked —
 * please contact us" (spec §1.8, owner's decision 2026-09-19): the person is the account's owner,
 * not a stranger guessing, since they proved the password first.
 */
final class CustomerBlocked extends AccessError
{
    public function __construct()
    {
        parent::__construct('This account is blocked.');
    }

    public function type(): string
    {
        return 'access.customer_blocked';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
