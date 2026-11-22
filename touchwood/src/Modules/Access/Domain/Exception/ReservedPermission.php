<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A role was given a permission only Super Admins hold (Access spec §1.5).
 */
final class ReservedPermission extends AccessError
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct("\"{$permission}\" belongs to Super Admins only and cannot be put in a role.");
    }

    public function type(): string
    {
        return 'access.reserved_permission';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['permission' => $this->permission];
    }
}
