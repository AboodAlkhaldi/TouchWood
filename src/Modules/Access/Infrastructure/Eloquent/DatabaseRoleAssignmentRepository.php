<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Access\Domain\Model\RoleAssignment;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\ValueObject\StoreChoice;
use Modules\Access\Public\Enums\AccessLevel;
use stdClass;

/**
 * An assignment is one row, its store row in a link table, and its exceptions with their own
 * stores in two more: every store id has a real foreign key (spec §5.4).
 */
final readonly class DatabaseRoleAssignmentRepository implements RoleAssignmentRepository
{
    private const string ASSIGNMENTS = 'access.role_assignments';

    private const string STORES = 'access.role_assignment_stores';

    private const string EXCEPTIONS = 'access.role_assignment_exceptions';

    private const string EXCEPTION_STORES = 'access.role_assignment_exception_stores';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function byStaff(string $staffId): ?RoleAssignment
    {
        $row = $this->db->table(self::ASSIGNMENTS)->where('staff_user_id', $staffId)->lockForUpdate()->first();

        return $row instanceof stdClass ? $this->load([$row])[0] : null;
    }

    public function holdersOf(string $roleId): array
    {
        $rows = $this->db->table(self::ASSIGNMENTS)->where('role_id', $roleId)->orderBy('staff_user_id')->lockForUpdate()->get()->all();

        /** @var list<stdClass> $rows */
        return $this->load($rows);
    }

    public function save(RoleAssignment $assignment): void
    {
        $staffId = $assignment->staffId();

        $this->db->table(self::ASSIGNMENTS)->upsert([[
            'staff_user_id' => $staffId,
            'role_id' => $assignment->roleId(),
            'access_level' => $assignment->stores()->level->value,
            'assigned_by' => $assignment->assignedBy(),
            'assigned_at' => $assignment->assignedAt(),
        ]], ['staff_user_id'], ['role_id', 'access_level', 'assigned_by', 'assigned_at']);

        // The exceptions' stores go with them (ON DELETE CASCADE).
        $this->db->table(self::STORES)->where('staff_user_id', $staffId)->delete();
        $this->db->table(self::EXCEPTIONS)->where('staff_user_id', $staffId)->delete();

        $this->db->table(self::STORES)->insert(array_map(
            fn (string $storeId): array => ['staff_user_id' => $staffId, 'store_id' => $storeId],
            $assignment->stores()->storeIds(),
        ));

        foreach ($assignment->exceptions() as $permission => $stores) {
            $this->db->table(self::EXCEPTIONS)->insert([
                'staff_user_id' => $staffId,
                'permission' => $permission,
                'access_level' => $stores->level->value,
            ]);
            $this->db->table(self::EXCEPTION_STORES)->insert(array_map(
                fn (string $storeId): array => ['staff_user_id' => $staffId, 'permission' => $permission, 'store_id' => $storeId],
                $stores->storeIds(),
            ));
        }
    }

    public function delete(string $staffId): void
    {
        // Stores, exceptions and their stores cascade.
        $this->db->table(self::ASSIGNMENTS)->where('staff_user_id', $staffId)->delete();
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<RoleAssignment>
     */
    private function load(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $staffIds = array_map(fn (stdClass $row): string => (string) $row->staff_user_id, $rows);

        $stores = [];

        foreach ($this->db->table(self::STORES)->whereIn('staff_user_id', $staffIds)->orderBy('store_id')->get() as $store) {
            $stores[$store->staff_user_id][] = (string) $store->store_id;
        }

        $exceptionLevels = [];
        $exceptionStores = [];

        foreach ($this->db->table(self::EXCEPTIONS)->whereIn('staff_user_id', $staffIds)->get() as $exception) {
            $exceptionLevels[$exception->staff_user_id][$exception->permission] = AccessLevel::from((string) $exception->access_level);
        }

        foreach ($this->db->table(self::EXCEPTION_STORES)->whereIn('staff_user_id', $staffIds)->orderBy('store_id')->get() as $store) {
            $exceptionStores[$store->staff_user_id][$store->permission][] = (string) $store->store_id;
        }

        $assignments = [];

        foreach ($rows as $row) {
            $staffId = (string) $row->staff_user_id;
            $exceptions = [];

            foreach ($exceptionLevels[$staffId] ?? [] as $permission => $level) {
                $exceptions[(string) $permission] = StoreChoice::of($level, $level === AccessLevel::AllStores ? [] : $exceptionStores[$staffId][$permission] ?? []);
            }

            $level = AccessLevel::from((string) $row->access_level);

            $assignments[] = RoleAssignment::reconstitute(
                $staffId,
                (string) $row->role_id,
                StoreChoice::of($level, $level === AccessLevel::AllStores ? [] : $stores[$staffId] ?? []),
                $exceptions,
                $row->assigned_by === null ? null : (string) $row->assigned_by,
                CarbonImmutable::parse((string) $row->assigned_at),
            );
        }

        return $assignments;
    }
}
