<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * C3 — inviting a staff member (frontend.md §3.3).
 *
 * Three steps: who they are, what they may do, and where. Nothing is written until the last one,
 * so leaving halfway sends no invitation and creates nobody [decided 2026-09-19].
 */
#[TypeScript]
final class InviteStaffPage extends Data
{
    /**
     * @param  list<RoleRow>  $savedRoles  every saved role; the screen shows those of the level being invited
     * @param  array<string, list<string>>  $savedPermissions  what each of them holds
     * @param  list<EditorPermissionRow>  $permissions  what may go in a role of staff level
     * @param  list<EditorPermissionRow>  $adminPermissions  and of admin level; empty unless a Super Admin is asking
     * @param  list<PermissionGroupRow>  $groups
     * @param  list<StoreOption>  $stores  only the stores the inviter may give away
     * @param  list<CountryOption>  $countries
     */
    public function __construct(
        public array $savedRoles,
        public array $savedPermissions,
        public array $permissions,
        public array $adminPermissions,
        public array $groups,
        public array $stores,
        public array $countries,
        /** Only a Super Admin may bring in an admin (access.md §1.6). */
        public bool $maySetAdmin,
        /** The inviting admin's own language, which the form starts from. */
        public string $locale,
    ) {}
}
