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
     * when the change does. There is no button for it (amendment 59): after a hand edit of the
     * database, `php artisan cache:clear`.
     */
    public function refresh(string ...$staffIds): void;
}
