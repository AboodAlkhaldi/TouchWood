<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\RoleEditorPermissions;

use Modules\Access\Domain\ValueObject\RoleLevel;

/**
 * What the role editor shows for a role of this level: every action a role may hold, whether the
 * author may give it, and in which stores (spec §3.2, amendment 8). Only a Super Admin edits admin
 * roles.
 */
final readonly class RoleEditorPermissions
{
    public function __construct(
        public RoleLevel $level = RoleLevel::Staff,
    ) {}
}
