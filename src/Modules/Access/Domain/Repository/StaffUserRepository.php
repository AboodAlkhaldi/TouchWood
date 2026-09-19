<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use Modules\Access\Domain\Model\StaffUser;

interface StaffUserRepository
{
    /**
     * Locks the row until the transaction ends, so two changes to one staff member queue up.
     */
    public function byId(string $id): ?StaffUser;

    /**
     * "First Last" for each id, in the order given; unknown ids are left out. No lock.
     *
     * @param  list<string>  $ids
     * @return array<string, string> id => name
     */
    public function names(array $ids): array;
}
