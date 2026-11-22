<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Unticking the last staff sign-in method: at least one stays ticked (amendment 91).
 */
final class SignInMethodRequired extends AccessError
{
    public function __construct()
    {
        parent::__construct('At least one sign-in method must stay on.');
    }

    public function type(): string
    {
        return 'access.sign_in_method_required';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
