<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RefreshRolePermissions;

/**
 * Rebuilds the cached permissions of everyone holding a role — the admin's button, a second guard
 * behind the automatic refresh (owner's decision, 2026-09-19).
 */
final readonly class RefreshRolePermissions
{
    public function __construct(
        public string $roleId,
    ) {}
}
