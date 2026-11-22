<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A wrong email or password — never saying which (spec §1.8).
 */
final class InvalidCredentials extends AccessError
{
    public function __construct()
    {
        parent::__construct('Wrong email or password.');
    }

    public function type(): string
    {
        return 'access.invalid_credentials';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
