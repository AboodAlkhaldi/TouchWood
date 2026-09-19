<?php

declare(strict_types=1);

namespace Modules\Access\Domain\Repository;

use Modules\Access\Domain\Exception\RoleNameTaken;
use Modules\Access\Domain\Model\Role;
use Modules\Access\Domain\ValueObject\RoleName;

interface RoleRepository
{
    public function nextId(): string;

    /**
     * Locks the row until the transaction ends.
     */
    public function byId(string $id): ?Role;

    /**
     * The staff member's personal role, if they have one. Locks it.
     */
    public function personalRoleOf(string $staffId): ?Role;

    /**
     * The name, in either language, that another saved role already uses — compared ignoring
     * case — or null when both are free.
     */
    public function savedNameInUse(RoleName $name, ?string $exceptRoleId = null): ?string;

    /**
     * @throws RoleNameTaken when another saved role took the name first
     */
    public function add(Role $role): void;

    /**
     * @throws RoleNameTaken when another saved role took the name first
     */
    public function update(Role $role): void;

    /**
     * Nobody may hold it any more: the assignments move first.
     */
    public function delete(string $id): void;
}
