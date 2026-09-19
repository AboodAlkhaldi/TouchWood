<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A management action put in a staff role: only admin roles may hold it (owner's decision,
 * 2026-09-19).
 */
final class AdminOnlyPermission extends AccessError
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct("\"{$permission}\" is a management action and can be put only in an admin role.");
    }

    public function type(): string
    {
        return 'access.admin_only_permission';
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
