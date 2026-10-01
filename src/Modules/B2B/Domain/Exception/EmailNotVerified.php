<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Sending an application before the account's email address is confirmed (b2b.md §1.2, §7).
 * Nothing else about the account is asked for: the phone belongs to ordering, not to applying.
 */
final class EmailNotVerified extends B2BError
{
    public function __construct()
    {
        parent::__construct('Confirm your email address before you send the application.');
    }

    public function type(): string
    {
        return 'b2b.email_not_verified';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
