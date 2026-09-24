<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * D1 — the saved roles (frontend.md §3.4).
 *
 * Personal roles are never here: a role made for one person is that person's business, and the list
 * is about the roles an admin hands out (access.md §1.5).
 */
#[TypeScript]
final class RolesPage extends Data
{
    /**
     * @param  list<RoleRow>  $roles
     * @param  list<PermissionGroupRow>  $groups  the business areas, in the order the editor shows
     *                                            them, for the table of roles against areas
     * @param  list<RolePermissionRow>  $permissions  every declared action, for the table's rows:
     *                                                one row each, under the heading of its area
     *                                                (owner, 2026-09-24)
     * @param  array<string, list<string>>  $permissionsByRole  what each role holds, so a cell can
     *                                                          be answered without asking again
     * @param  bool  $mayCreate  whether this reader may add a role at all
     */
    public function __construct(
        public array $roles,
        public array $groups,
        public array $permissions,
        public array $permissionsByRole,
        public bool $mayCreate,
    ) {}
}
