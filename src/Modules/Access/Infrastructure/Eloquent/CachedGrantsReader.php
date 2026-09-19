<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Authorization\StaffGrants;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Domain\ValueObject\StoreChoice;
use Modules\Access\Public\Enums\AccessLevel;
use Modules\Access\Public\Enums\StaffStatus;
use Shared\Infrastructure\Cache\VersionedCache;
use stdClass;

/**
 * Each staff member's permissions, cached under their own version (spec §5.4). A change replaces
 * the version inside its transaction, so a check never sees old permissions; nothing is kept in
 * the process, because queue workers live for hours.
 */
final readonly class CachedGrantsReader implements GrantsReader
{
    /** Safety net only: every change replaces the version at once (owner, 2026-09-19: 1 hour). */
    private const int SNAPSHOT_SECONDS = 3600;

    public function __construct(
        private Cache $cache,
        private Connection $db,
    ) {}

    public function forStaff(string $staffId): ?StaffGrants
    {
        if (! Ulids::valid($staffId)) {
            return null;
        }

        $staffId = strtolower($staffId);

        $snapshot = $this->cacheFor($staffId)->remember(fn (): ?array => $this->load($staffId)?->toSnapshot());

        return $snapshot === null ? null : StaffGrants::fromSnapshot($staffId, $snapshot);
    }

    public function refresh(string ...$staffIds): void
    {
        foreach (array_unique($staffIds) as $staffId) {
            $this->cacheFor(strtolower($staffId))->invalidate();
        }
    }

    private function cacheFor(string $staffId): VersionedCache
    {
        return new VersionedCache($this->cache, $this->db, "access:staff-grants:{$staffId}", self::SNAPSHOT_SECONDS);
    }

    private function load(string $staffId): ?StaffGrants
    {
        $staff = $this->db->table('access.staff_users')->where('id', $staffId)->first(['status', 'is_super_admin']);

        if (! $staff instanceof stdClass) {
            return null;
        }

        $status = StaffStatus::from((string) $staff->status);
        $superAdmin = (bool) $staff->is_super_admin;

        $assignment = $this->db->table('access.role_assignments as a')
            ->join('access.roles as r', 'r.id', '=', 'a.role_id')
            ->where('a.staff_user_id', $staffId)
            ->first(['a.role_id', 'a.access_level', 'r.level']);

        if (! $assignment instanceof stdClass) {
            return new StaffGrants($staffId, $status, $superAdmin, null, null, [], null);
        }

        $level = AccessLevel::from((string) $assignment->access_level);
        /** @var list<string> $storeIds */
        $storeIds = $this->db->table('access.role_assignment_stores')->where('staff_user_id', $staffId)->orderBy('store_id')->pluck('store_id')->all();
        $row = StoreChoice::of($level, $level === AccessLevel::AllStores ? [] : $storeIds);

        $exceptions = $this->exceptions($staffId);
        $grants = [];

        foreach ($this->db->table('access.role_permissions')->where('role_id', $assignment->role_id)->pluck('permission') as $permission) {
            $grants[(string) $permission] = $exceptions[$permission] ?? $row;
        }

        $stores = $row;

        foreach ($exceptions as $exception) {
            $stores = $stores->union($exception);
        }

        return new StaffGrants($staffId, $status, $superAdmin, RoleLevel::from((string) $assignment->level), (string) $assignment->role_id, $grants, $stores);
    }

    /**
     * @return array<string, StoreChoice>
     */
    private function exceptions(string $staffId): array
    {
        $rows = $this->db->table('access.role_assignment_exceptions as e')
            ->leftJoin('access.role_assignment_exception_stores as s', function ($join): void {
                $join->on('s.staff_user_id', '=', 'e.staff_user_id')->on('s.permission', '=', 'e.permission');
            })
            ->where('e.staff_user_id', $staffId)
            ->orderBy('s.store_id')
            ->get(['e.permission', 'e.access_level', 's.store_id']);

        $levels = [];
        $stores = [];

        foreach ($rows as $row) {
            $levels[(string) $row->permission] = AccessLevel::from((string) $row->access_level);

            if ($row->store_id !== null) {
                $stores[(string) $row->permission][] = (string) $row->store_id;
            }
        }

        $exceptions = [];

        foreach ($levels as $permission => $level) {
            $exceptions[$permission] = StoreChoice::of($level, $level === AccessLevel::AllStores ? [] : $stores[$permission] ?? []);
        }

        return $exceptions;
    }
}
