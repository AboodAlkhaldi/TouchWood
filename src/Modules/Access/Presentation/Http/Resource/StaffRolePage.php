<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * C6 — one person's role and stores (frontend.md §3.3).
 *
 * A role says what somebody may do; the stores say where. They are two questions, and this screen
 * is where the second one is answered — a role itself never carries stores.
 *
 * Editing a saved role here does not change it for everyone holding it: it becomes this person's
 * own role instead (access.md §1.5).
 */
#[TypeScript]
final class StaffRolePage extends Data
{
    /**
     * @param  list<RoleRow>  $savedRoles  the saved roles that may be given, at this person's level
     * @param  array<string, list<string>>  $savedPermissions  what each of those roles holds, so the
     *                                                         screen can tell an untouched role from
     *                                                         an edited one
     * @param  list<EditorPermissionRow>  $permissions  everything that may go in a role of this
     *                                                  level, for building one of their own
     * @param  list<PermissionGroupRow>  $groups
     * @param  list<StoreOption>  $stores  only the stores the reader may give away
     * @param  list<string>  $chosen  the actions the person holds now
     * @param  list<string>  $storeIds  the stores their role reaches now
     * @param  array<string, list<string>>  $exceptions  an action given stores of its own
     */
    public function __construct(
        public string $staffId,
        public string $staffName,
        public array $savedRoles,
        public array $savedPermissions,
        public array $permissions,
        public array $groups,
        public array $stores,
        public ?string $roleId,
        /** Whether the role they hold now is their own rather than a saved one. */
        public bool $personal,
        public string $accessLevel,
        public array $chosen,
        public array $storeIds,
        public array $exceptions,
    ) {}
}
