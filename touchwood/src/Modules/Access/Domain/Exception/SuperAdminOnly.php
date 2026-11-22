<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Only a Super Admin creates, clones, edits, deletes or assigns admin roles (owner's decision,
 * 2026-09-19).
 */
final class SuperAdminOnly extends AccessError
{
    /**
     * @param  string|null  $roleId  the admin role concerned; null for one not created yet
     */
    public function __construct(public readonly ?string $roleId = null)
    {
        parent::__construct('Only a Super Admin can create, change or assign an admin role'.($roleId === null ? '.' : " (\"{$roleId}\")."));
    }

    public function type(): string
    {
        return 'access.super_admin_only';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Forbidden;
    }
}
