<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Three sign-ins in 24 hours ran out of wrong app codes: the app step, recovery codes included, is locked until the password is reset (amendment 91; owner, 2026-10-31).
 */
final class AppSignInLocked extends AccessError
{
    public function __construct()
    {
        parent::__construct('Signing in with the authenticator app is locked; reset your password to unlock it.');
    }

    public function type(): string
    {
        return 'access.app_sign_in_locked';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
