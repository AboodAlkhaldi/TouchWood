<?php

declare(strict_types=1);

namespace Modules\Access\Application\Authorization;

/**
 * Staff members' permissions, cached (Access spec §5.4). Once warm, reading one reads only the
 * cache table: two small queries, the version and the snapshot.
 */
interface GrantsReader
{
    /**
     * @return StaffGrants|null null when no staff member has this id
     */
    public function forStaff(string $staffId): ?StaffGrants;

    /**
     * Call inside the transaction of the change: the rebuilt permissions become visible exactly
     * when the change does. Also what an admin's "refresh" button runs (owner, 2026-09-19).
     */
    public function refresh(string ...$staffIds): void;
}
