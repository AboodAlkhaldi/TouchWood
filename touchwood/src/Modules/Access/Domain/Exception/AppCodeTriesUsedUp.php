<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Too many wrong app or recovery codes for one sign-in: it ends, and the password is asked again - as a dead SMS code needs a new one (amendment 91).
 */
final class AppCodeTriesUsedUp extends AccessError
{
    public function __construct()
    {
        parent::__construct('Too many wrong codes. Sign in again.');
    }

    public function type(): string
    {
        return 'access.app_code_tries_used_up';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
