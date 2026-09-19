<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Permission;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Psr\Log\LoggerInterface;

/**
 * Carries renamed and removed permissions into the roles and exceptions, at the end of every
 * `php artisan migrate` (owner's decisions, 2026-09-19). A data migration, run as the system: it
 * checks no permission, and audits every role and staff member it changes.
 *
 * A name that is neither declared nor declared removed is left alone and reported — it grants
 * nothing, and a module switched off by mistake must not wipe everyone's roles.
 */
final readonly class PermissionSync
{
    public function __construct(
        private Connection $db,
        private InMemoryPermissionCatalog $catalog,
        private PlatformApi $platform,
        private GrantsReader $grants,
        private LoggerInterface $logger,
    ) {}

    /**
     * A migrated or rolled-back schema must not be served from permissions cached on the old one,
     * as Platform does for its own caches.
     */
    public function refreshEveryone(): void
    {
        if (! $this->db->getSchemaBuilder()->hasTable('access.staff_users')) {
            return;
        }

        /** @var list<string> $staffIds */
        $staffIds = $this->db->table('access.staff_users')->pluck('id')->all();
        $this->grants->refresh(...$staffIds);
    }

    /**
     * @return array{roles: int, staff: int, unknown: list<string>} what changed, and the names left alone
     */
    public function run(): array
    {
        // A migrate that stopped before Access's tables (--path) has nothing to carry.
        if (! $this->db->getSchemaBuilder()->hasTable('access.role_assignment_exceptions')) {
            return ['roles' => 0, 'staff' => 0, 'unknown' => []];
        }

        // Roles, then assignments, are locked as the handlers lock them; a deadlock is retried.
        $result = $this->db->transaction(fn (): array => [
            'roles' => $this->syncRoles(),
            'staff' => $this->syncExceptions(),
        ], 3);

        $unknown = $this->unknownNames();

        if ($unknown !== []) {
            $this->logger->warning('Roles hold permissions that no module declares; they grant nothing and were left alone.', ['permissions' => $unknown]);
        }

        return [...$result, 'unknown' => $unknown];
    }

    private function syncRoles(): int
    {
        $renames = $this->catalog->renames();
        $removals = $this->catalog->removals();
        $old = [...array_keys($renames), ...$removals];

        if ($old === []) {
            return 0;
        }

        /** @var list<string> $affected */
        $affected = $this->db->table('access.role_permissions')->whereIn('permission', $old)->distinct()->pluck('role_id')->all();

        if ($affected === []) {
            return 0;
        }

        /** @var array<string, string> $levels role id => level, locked in id order */
        $levels = $this->db->table('access.roles')->whereIn('id', $affected)->orderBy('id')->lockForUpdate()->pluck('level', 'id')->all();
        $holders = [];

        foreach ($levels as $roleId => $level) {
            /** @var list<string> $before */
            $before = $this->db->table('access.role_permissions')->where('role_id', $roleId)->orderBy('permission')->pluck('permission')->all();
            $after = [];

            foreach ($before as $permission) {
                if (in_array($permission, $removals, true)) {
                    continue;
                }

                $to = $renames[$permission] ?? $permission;

                // A management action never enters a staff role, whatever a rename says.
                if ($level === RoleLevel::Staff->value && $to !== $permission && in_array($to, AccessPermissions::adminOnly(), true)) {
                    $this->logger->warning('A rename would put a management action into a staff role; it was taken out instead.', ['role_id' => $roleId, 'permission' => $permission]);

                    continue;
                }

                $after[] = $to;
            }

            $after = array_values(array_unique($after));
            sort($after);

            $this->db->table('access.role_permissions')->where('role_id', $roleId)->delete();
            $this->db->table('access.role_permissions')->insert(array_map(
                fn (string $permission): array => ['role_id' => $roleId, 'permission' => $permission],
                $after,
            ));

            if ($after === []) {
                $this->logger->warning('A role lost its last action to a removed permission; an admin must give it one.', ['role_id' => $roleId]);
            }

            $this->platform->recordAudit(RoleAudit::permissionsSynced($roleId, $before, $after));

            /** @var list<string> $roleHolders */
            $roleHolders = $this->db->table('access.role_assignments')->where('role_id', $roleId)->pluck('staff_user_id')->all();
            array_push($holders, ...$roleHolders);
        }

        $this->grants->refresh(...$holders);

        return count($levels);
    }

    private function syncExceptions(): int
    {
        $renames = $this->catalog->renames();
        $removals = $this->catalog->removals();
        $old = [...array_keys($renames), ...$removals];

        if ($old === []) {
            return 0;
        }

        /** @var list<string> $affected */
        $affected = $this->db->table('access.role_assignment_exceptions')->whereIn('permission', $old)->distinct()->pluck('staff_user_id')->all();

        /** @var list<string> $staffIds locked in id order */
        $staffIds = $affected === [] ? [] : $this->db->table('access.role_assignments')->whereIn('staff_user_id', $affected)
            ->orderBy('staff_user_id')->lockForUpdate()->pluck('staff_user_id')->all();

        foreach ($staffIds as $staffId) {
            /** @var list<string> $before */
            $before = $this->db->table('access.role_assignment_exceptions')->where('staff_user_id', $staffId)->orderBy('permission')->pluck('permission')->all();
            // The names this person's exceptions have after each step: a rename never lands on one.
            $present = $before;

            foreach ($before as $permission) {
                if (! in_array($permission, $old, true)) {
                    continue;
                }

                $to = $renames[$permission] ?? null;
                $keep = $to !== null
                    && ! in_array($to, $present, true)
                    // A store-free action takes no stores of its own.
                    && $this->catalog->definition($to)?->kind === PermissionKind::PerStore;

                $row = $this->db->table('access.role_assignment_exceptions')->where('staff_user_id', $staffId)->where('permission', $permission);

                // Its stores follow it (ON UPDATE CASCADE) or go with it (ON DELETE CASCADE).
                $keep ? $row->update(['permission' => $to]) : $row->delete();
                $present = array_values(array_diff($present, [$permission]));

                if ($keep) {
                    $present[] = (string) $to;
                }
            }

            // An exception is only for an action of the person's role (spec §5.4).
            $this->db->table('access.role_assignment_exceptions as e')
                ->where('e.staff_user_id', $staffId)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('access.role_assignments as a')
                    ->join('access.role_permissions as p', 'p.role_id', '=', 'a.role_id')
                    ->whereColumn('a.staff_user_id', 'e.staff_user_id')
                    ->whereColumn('p.permission', 'e.permission'))
                ->delete();

            /** @var list<string> $after */
            $after = $this->db->table('access.role_assignment_exceptions')->where('staff_user_id', $staffId)->orderBy('permission')->pluck('permission')->all();
            $this->platform->recordAudit(RoleAudit::exceptionsSynced($staffId, $before, $after));
        }

        $this->grants->refresh(...$staffIds);

        return count($staffIds);
    }

    /**
     * @return list<string>
     */
    private function unknownNames(): array
    {
        $stored = $this->db->table('access.role_permissions')->distinct()->pluck('permission')
            ->merge($this->db->table('access.role_assignment_exceptions')->distinct()->pluck('permission'))
            ->unique()->sort()->values()->all();

        return array_values(array_filter(
            array_map(strval(...), $stored),
            fn (string $name): bool => $this->catalog->definition($name) === null,
        ));
    }
}
