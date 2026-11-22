<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

final class RoleNotFound extends AccessError
{
    public function __construct(public readonly string $roleId)
    {
        parent::__construct("No role has the id \"{$roleId}\".");
    }

    public function type(): string
    {
        return 'access.role_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
