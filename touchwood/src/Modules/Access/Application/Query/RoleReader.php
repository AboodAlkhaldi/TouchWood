<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query;

/**
 * Reads roles for the role screens (spec §3.2, amendment 8). Plain rows: the handlers decide what
 * each reader may see.
 *
 * @phpstan-type RoleRow array{id: string, name_ar: string, name_en: string, level: string, permission_count: int, holder_count: int}
 * @phpstan-type HolderRow array{staff_id: string, first_name: string, last_name: string}
 */
interface RoleReader
{
    /**
     * @return list<RoleRow> every saved role, by English name
     */
    public function savedRoles(): array;

    /**
     * @return array{role: RoleRow, permissions: list<string>}|null null for an unknown or personal role
     */
    public function savedRole(string $roleId): ?array;

    /**
     * Every saved role's actions, in one read.
     *
     * The roles screen shows which business areas each role reaches into, side by side (frontend.md
     * 3.4, D1). Asking role by role would be one query per row on a list screen, which is the kind
     * of thing that is invisible with five roles and painful with fifty.
     *
     * @return array<string, list<string>> role id => the actions it holds
     */
    public function savedRolePermissions(): array;

    /**
     * @return list<HolderRow> by name
     */
    public function holders(string $roleId): array;
}
