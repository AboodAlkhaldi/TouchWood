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

    /**
     * One statement, so the snapshot is one consistent moment: separate reads could combine a
     * change's new role with its old stores while that change commits.
     */
    private function load(string $staffId): ?StaffGrants
    {
        $row = $this->db->selectOne(<<<'SQL'
            SELECT s.status, s.is_super_admin, s.session_version, a.role_id, a.access_level, r.level,
                (SELECT coalesce(json_agg(st.store_id ORDER BY st.store_id), '[]')
                    FROM access.role_assignment_stores st WHERE st.staff_user_id = s.id) AS stores,
                (SELECT coalesce(json_agg(p.permission ORDER BY p.permission), '[]')
                    FROM access.role_permissions p WHERE p.role_id = a.role_id) AS permissions,
                (SELECT coalesce(json_agg(json_build_object(
                        'permission', e.permission,
                        'level', e.access_level,
                        'stores', (SELECT coalesce(json_agg(es.store_id ORDER BY es.store_id), '[]')
                            FROM access.role_assignment_exception_stores es
                            WHERE es.staff_user_id = e.staff_user_id AND es.permission = e.permission)
                    )), '[]')
                    FROM access.role_assignment_exceptions e WHERE e.staff_user_id = s.id) AS exceptions
            FROM access.staff_users s
            LEFT JOIN access.role_assignments a ON a.staff_user_id = s.id
            LEFT JOIN access.roles r ON r.id = a.role_id
            WHERE s.id = ?
            SQL, [$staffId]);

        if (! $row instanceof stdClass) {
            return null;
        }

        $status = StaffStatus::from((string) $row->status);
        $superAdmin = (bool) $row->is_super_admin;
        $sessionVersion = (int) $row->session_version;

        if ($row->role_id === null) {
            return new StaffGrants($staffId, $status, $superAdmin, null, null, [], null, $sessionVersion);
        }

        $storeRow = $this->choice((string) $row->access_level, self::strings(self::json((string) $row->stores)));
        $exceptions = [];

        foreach (self::json((string) $row->exceptions) as $exception) {
            if (is_array($exception)) {
                $exceptions[(string) $exception['permission']] = $this->choice((string) $exception['level'], self::strings(is_array($exception['stores']) ? $exception['stores'] : []));
            }
        }

        $grants = [];

        foreach (self::strings(self::json((string) $row->permissions)) as $permission) {
            $grants[$permission] = $exceptions[$permission] ?? $storeRow;
        }

        $stores = $storeRow;

        foreach ($exceptions as $exception) {
            $stores = $stores->union($exception);
        }

        return new StaffGrants($staffId, $status, $superAdmin, RoleLevel::from((string) $row->level), (string) $row->role_id, $grants, $stores, $sessionVersion);
    }

    /**
     * @param  list<string>  $storeIds
     */
    private function choice(string $level, array $storeIds): StoreChoice
    {
        $level = AccessLevel::from($level);

        return StoreChoice::of($level, $level === AccessLevel::AllStores ? [] : $storeIds);
    }

    /**
     * @return array<mixed>
     */
    private static function json(string $json): array
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return array_values(array_map(strval(...), array_filter($values, is_string(...))));
    }
}
