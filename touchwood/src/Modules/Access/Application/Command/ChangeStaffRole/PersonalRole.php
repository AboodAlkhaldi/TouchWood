<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\ChangeStaffRole;

use Modules\Access\Domain\ValueObject\RoleLevel;

/**
 * The staff member's own role: a saved role the admin edited (every action shown, ticked or not,
 * within the admin's own permissions), or one built from scratch (owner, 2026-09-19).
 */
final readonly class PersonalRole
{
    /**
     * @param  list<string>  $permissions  at least one
     */
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public array $permissions,
        public RoleLevel $level = RoleLevel::Staff,
    ) {}
}
