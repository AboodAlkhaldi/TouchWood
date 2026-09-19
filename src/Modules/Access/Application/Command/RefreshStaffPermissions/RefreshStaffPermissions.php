<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RefreshStaffPermissions;

/**
 * Rebuilds one staff member's cached permissions — the admin's button, a second guard behind the
 * automatic refresh (owner's decision, 2026-09-19).
 */
final readonly class RefreshStaffPermissions
{
    public function __construct(
        public string $staffId,
    ) {}
}
