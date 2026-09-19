<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A link that does not exist, was replaced by a newer one, was used already, or ran out. Never
 * says which, so a guessed link teaches nothing.
 */
final class InvalidOrExpiredLink extends AccessError
{
    public function __construct()
    {
        parent::__construct('This link is not valid or has expired.');
    }

    public function type(): string
    {
        return 'access.invalid_or_expired_link';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
