<?php

declare(strict_types=1);

namespace Modules\Access\Application\Authorization;

use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\StoreChoice;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;

/**
 * What one staff member may do, and where: their status, whether they are a Super Admin, and each
 * action of their role with its stores. Built from the database and cached (spec §5.4); the cache
 * holds only plain arrays (config/cache.php refuses to rebuild objects).
 *
 * @phpstan-type Stores array{all: bool, ids: list<string>}
 * @phpstan-type Snapshot array{status: string, super_admin: bool, level: string|null, role_id: string|null, stores: Stores|null, grants: array<string, Stores>}
 */
final readonly class StaffGrants
{
    /**
     * @param  array<string, StoreChoice>  $grants  each action of their role => its stores
     * @param  StoreChoice|null  $stores  the staff member's stores; null when they have no role
     */
    public function __construct(
        public string $staffId,
        public StaffStatus $status,
        public bool $superAdmin,
        public ?RoleLevel $level,
        public ?string $roleId,
        public array $grants,
        public ?StoreChoice $stores,
    ) {}

    public static function of(string $staffId, StaffStatus $status, bool $superAdmin, ?Role $role, ?RoleAssignment $assignment): self
    {
        $grants = [];

        if ($role !== null && $assignment !== null) {
            foreach ($role->permissions() as $permission) {
                $grants[$permission] = $assignment->storesFor($permission);
            }
        }

        return new self(
            $staffId,
            $status,
            $superAdmin,
            $role?->level(),
            $role?->id(),
            $grants,
            $assignment?->staffStores(),
        );
    }

    public function isActive(): bool
    {
        return $this->status === StaffStatus::Active;
    }

    /**
     * Holds an admin role (a Super Admin holds no role).
     */
    public function isAdmin(): bool
    {
        return $this->level === RoleLevel::Admin;
    }

    /**
     * The stores this action of their role reaches, or null when their role does not hold it.
     */
    public function storesFor(string $permission): ?StoreChoice
    {
        return $this->grants[$permission] ?? null;
    }

    /**
     * @return Snapshot
     */
    public function toSnapshot(): array
    {
        return [
            'status' => $this->status->value,
            'super_admin' => $this->superAdmin,
            'level' => $this->level?->value,
            'role_id' => $this->roleId,
            'stores' => $this->stores === null ? null : self::storesToArray($this->stores),
            'grants' => array_map(self::storesToArray(...), $this->grants),
        ];
    }

    /**
     * @param  Snapshot  $snapshot
     */
    public static function fromSnapshot(string $staffId, array $snapshot): self
    {
        return new self(
            $staffId,
            StaffStatus::from($snapshot['status']),
            $snapshot['super_admin'],
            $snapshot['level'] === null ? null : RoleLevel::from($snapshot['level']),
            $snapshot['role_id'],
            array_map(self::storesFromArray(...), $snapshot['grants']),
            $snapshot['stores'] === null ? null : self::storesFromArray($snapshot['stores']),
        );
    }

    /**
     * @return Stores
     */
    private static function storesToArray(StoreChoice $stores): array
    {
        return ['all' => $stores->isAllStores(), 'ids' => $stores->storeIds()];
    }

    /**
     * @param  Stores  $stores
     */
    private static function storesFromArray(array $stores): StoreChoice
    {
        return $stores['all'] ? StoreChoice::allStores() : StoreChoice::of(AccessLevel::SelectedStores, $stores['ids']);
    }
}
