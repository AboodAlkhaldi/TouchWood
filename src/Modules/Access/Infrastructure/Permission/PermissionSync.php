<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Permission;

use Illuminate\Database\Connection;
use Modules\Access\Application\Audit\RoleAudit;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
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
     * @return array{roles: int, staff: int, unknown: list<string>} what changed, and the names left alone
     */
    public function run(): array
    {
        // A migrate that stopped before Access's tables (--path) has nothing to carry.
        if (! $this->db->getSchemaBuilder()->hasTable('access.role_assignment_exceptions')) {
            return ['roles' => 0, 'staff' => 0, 'unknown' => []];
        }

        $result = $this->db->transaction(fn (): array => [
            'roles' => $this->syncRoles(),
            'staff' => $this->syncExceptions(),
        ]);

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

        /** @var list<string> $roleIds */
        $roleIds = $this->db->table('access.role_permissions')->whereIn('permission', $old)->distinct()->orderBy('role_id')->pluck('role_id')->all();
        $holders = [];

        foreach ($roleIds as $roleId) {
            /** @var list<string> $before */
            $before = $this->db->table('access.role_permissions')->where('role_id', $roleId)->orderBy('permission')->pluck('permission')->all();
            $after = [];

            foreach ($before as $permission) {
                if (! in_array($permission, $removals, true)) {
                    $after[] = $renames[$permission] ?? $permission;
                }
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

        return count($roleIds);
    }

    private function syncExceptions(): int
    {
        $renames = $this->catalog->renames();
        $removals = $this->catalog->removals();
        $old = [...array_keys($renames), ...$removals];

        if ($old === []) {
            return 0;
        }

        /** @var list<string> $staffIds */
        $staffIds = $this->db->table('access.role_assignment_exceptions')->whereIn('permission', $old)->distinct()->orderBy('staff_user_id')->pluck('staff_user_id')->all();

        foreach ($staffIds as $staffId) {
            $exceptions = fn (): array => $this->db->table('access.role_assignment_exceptions')->where('staff_user_id', $staffId)->orderBy('permission')->pluck('permission')->all();
            /** @var list<string> $before */
            $before = $exceptions();

            foreach ($before as $permission) {
                $to = $renames[$permission] ?? null;
                $keep = $to !== null
                    && ! in_array($to, $before, true)
                    // A store-free action takes no stores of its own.
                    && $this->catalog->definition($to)?->kind === PermissionKind::PerStore;

                if (! in_array($permission, $old, true)) {
                    continue;
                }

                $row = $this->db->table('access.role_assignment_exceptions')->where('staff_user_id', $staffId)->where('permission', $permission);

                // Its stores follow it (ON UPDATE CASCADE) or go with it (ON DELETE CASCADE).
                $keep ? $row->update(['permission' => $to]) : $row->delete();
            }

            /** @var list<string> $after */
            $after = $exceptions();
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
