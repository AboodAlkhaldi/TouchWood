<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * The right password, but an account that may not sign in — for staff, a disabled one (spec §1.8).
 * Said only after the right password, so it tells a stranger nothing.
 */
final class SignInRefused extends AccessError
{
    public function __construct()
    {
        parent::__construct('This account cannot sign in. Please contact an administrator.');
    }

    public function type(): string
    {
        return 'access.sign_in_refused';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
