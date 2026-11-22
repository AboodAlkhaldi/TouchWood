<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A wrong code from the authenticator app, or a recovery code that is not one of theirs or was used (amendment 91).
 */
final class WrongAppCode extends AccessError
{
    public function __construct()
    {
        parent::__construct('The code is wrong, or was already used.');
    }

    public function type(): string
    {
        return 'access.wrong_app_code';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
