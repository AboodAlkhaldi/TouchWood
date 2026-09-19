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
}
