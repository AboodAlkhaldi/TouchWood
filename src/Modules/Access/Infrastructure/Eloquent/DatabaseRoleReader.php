<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Access\Application\Query\RoleReader;
use Modules\Access\Domain\ValueObject\RoleKind;
use stdClass;

final readonly class DatabaseRoleReader implements RoleReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function savedRoles(): array
    {
        return array_values(array_map(
            $this->toRow(...),
            $this->roles()->orderByRaw("lower(r.name->>'en')")->get()->all(),
        ));
    }

    public function savedRole(string $roleId): ?array
    {
        if (! Ulids::valid($roleId)) {
            return null;
        }

        $row = $this->roles()->where('r.id', strtolower($roleId))->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var list<string> $permissions */
        $permissions = $this->db->table('access.role_permissions')->where('role_id', $row->id)->orderBy('permission')->pluck('permission')->all();

        return ['role' => $this->toRow($row), 'permissions' => $permissions];
    }

    public function savedRolePermissions(): array
    {
        $rows = $this->db->table('access.role_permissions as p')
            ->join('access.roles as r', 'r.id', '=', 'p.role_id')
            ->where('r.kind', RoleKind::Saved->value)
            ->orderBy('p.permission')
            ->get(['p.role_id', 'p.permission']);

        $byRole = [];

        foreach ($rows as $row) {
            $byRole[(string) $row->role_id][] = (string) $row->permission;
        }

        return $byRole;
    }

    public function holders(string $roleId): array
    {
        $rows = $this->db->table('access.role_assignments as a')
            ->join('access.staff_users as s', 's.id', '=', 'a.staff_user_id')
            ->where('a.role_id', $roleId)
            ->orderBy('s.first_name')->orderBy('s.last_name')->orderBy('s.id')
            ->get(['s.id', 's.first_name', 's.last_name']);

        $holders = [];

        foreach ($rows as $row) {
            $holders[] = ['staff_id' => (string) $row->id, 'first_name' => (string) $row->first_name, 'last_name' => (string) $row->last_name];
        }

        return $holders;
    }

    private function roles(): Builder
    {
        return $this->db->table('access.roles as r')
            ->where('r.kind', RoleKind::Saved->value)
            ->select('r.id', 'r.name', 'r.level')
            ->selectRaw('(SELECT count(*) FROM access.role_permissions p WHERE p.role_id = r.id) AS permission_count')
            ->selectRaw('(SELECT count(*) FROM access.role_assignments a WHERE a.role_id = r.id) AS holder_count');
    }

    /**
     * @return array{id: string, name_ar: string, name_en: string, level: string, permission_count: int, holder_count: int}
     */
    private function toRow(stdClass $row): array
    {
        /** @var array{ar: string, en: string} $name */
        $name = json_decode((string) $row->name, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => (string) $row->id,
            'name_ar' => $name['ar'],
            'name_en' => $name['en'],
            'level' => (string) $row->level,
            'permission_count' => (int) $row->permission_count,
            'holder_count' => (int) $row->holder_count,
        ];
    }
}
