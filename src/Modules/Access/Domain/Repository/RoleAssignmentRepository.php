<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use Modules\Access\Domain\Model\RoleAssignment;

interface RoleAssignmentRepository
{
    /**
     * The staff member's assignment, or null when they have no role. Locks it.
     */
    public function byStaff(string $staffId): ?RoleAssignment;

    /**
     * Everyone holding the role, locked, ordered by staff id.
     *
     * @return list<RoleAssignment>
     */
    public function holdersOf(string $roleId): array;

    /**
     * Writes the assignment with its stores and exceptions, replacing what was stored.
     */
    public function save(RoleAssignment $assignment): void;

    /**
     * The staff member holds no role any more (a Super Admin has none). Its stores and exceptions
     * go with it.
     */
    public function delete(string $staffId): void;
}
