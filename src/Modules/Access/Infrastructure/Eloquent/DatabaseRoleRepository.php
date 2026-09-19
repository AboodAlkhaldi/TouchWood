<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\ValueObject\RoleKind;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\RoleName;
use stdClass;

/**
 * Plain query builder: a role is one row plus its list of actions.
 */
final readonly class DatabaseRoleRepository implements RoleRepository
{
    private const string ROLES = 'access.roles';

    private const string PERMISSIONS = 'access.role_permissions';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function byId(string $id): ?Role
    {
        if (! Ulids::valid($id)) {
            return null;
        }

        $row = $this->db->table(self::ROLES)->where('id', strtolower($id))->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toRole($row) : null;
    }

    public function personalRoleOf(string $staffId): ?Role
    {
        if (! Ulids::valid($staffId)) {
            return null;
        }

        $row = $this->db->table(self::ROLES)->where('personal_to', strtolower($staffId))->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->toRole($row) : null;
    }

    public function savedNameInUse(RoleName $name, ?string $exceptRoleId = null): ?string
    {
        foreach (['ar' => $name->ar, 'en' => $name->en] as $locale => $value) {
            $taken = $this->db->table(self::ROLES)
                ->where('kind', RoleKind::Saved->value)
                ->whereRaw("lower(name->>'{$locale}') = lower(?)", [$value])
                ->when($exceptRoleId !== null, fn ($query) => $query->where('id', '<>', $exceptRoleId))
                ->exists();

            if ($taken) {
                return $value;
            }
        }

        return null;
    }

    public function add(Role $role): void
    {
        $now = CarbonImmutable::now();

        $this->writeNamed($role, function () use ($role, $now): void {
            $this->db->table(self::ROLES)->insert([
                'id' => $role->id(),
                'name' => $this->nameJson($role->name()),
                'kind' => $role->kind()->value,
                'level' => $role->level()->value,
                'personal_to' => $role->personalTo(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $this->writePermissions($role);
    }

    public function update(Role $role): void
    {
        $this->writeNamed($role, function () use ($role): void {
            $this->db->table(self::ROLES)->where('id', $role->id())->update([
                'name' => $this->nameJson($role->name()),
                'level' => $role->level()->value,
                'updated_at' => CarbonImmutable::now(),
            ]);
        });

        $this->db->table(self::PERMISSIONS)->where('role_id', $role->id())->delete();
        $this->writePermissions($role);
    }

    public function delete(string $id): void
    {
        // Its actions go with it (ON DELETE CASCADE).
        $this->db->table(self::ROLES)->where('id', $id)->delete();
    }

    /**
     * The code checks the name first (savedNameInUse); the unique index only catches two admins
     * saving the same name at the same moment.
     */
    private function writeNamed(Role $role, Closure $write): void
    {
        try {
            $write();
        } catch (UniqueConstraintViolationException $e) {
            $message = $e->getMessage();

            if (! str_contains($message, 'roles_saved_name_')) {
                throw $e;
            }

            throw new RoleNameTaken(str_contains($message, 'roles_saved_name_ar') ? $role->name()->ar : $role->name()->en);
        }
    }

    private function writePermissions(Role $role): void
    {
        $this->db->table(self::PERMISSIONS)->insert(array_map(
            fn (string $permission): array => ['role_id' => $role->id(), 'permission' => $permission],
            $role->permissions(),
        ));
    }

    private function toRole(stdClass $row): Role
    {
        /** @var array{ar: string, en: string} $name */
        $name = json_decode((string) $row->name, true, flags: JSON_THROW_ON_ERROR);

        /** @var list<string> $permissions */
        $permissions = $this->db->table(self::PERMISSIONS)->where('role_id', $row->id)->orderBy('permission')->pluck('permission')->all();

        return Role::reconstitute(
            (string) $row->id,
            RoleKind::from((string) $row->kind),
            RoleLevel::from((string) $row->level),
            $row->personal_to === null ? null : (string) $row->personal_to,
            RoleName::of($name['ar'], $name['en']),
            $permissions,
        );
    }

    private function nameJson(RoleName $name): string
    {
        return json_encode(['ar' => $name->ar, 'en' => $name->en], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
