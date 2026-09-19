<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\EnableStaff;

use Modules\Access\Application\Command\ChangeStaffRole\ChangeStaffRole;

/**
 * A disabled staff member back at work. Someone who never accepted their invitation gets a new one
 * instead, because they have no password yet (spec §4.3). Nobody works without a role: someone who
 * has none is enabled only together with one, given by the same admin (owner, 2026-09-19).
 */
final readonly class EnableStaff
{
    /**
     * @param  ChangeStaffRole|null  $role  required when they have no role; optional otherwise
     */
    public function __construct(
        public string $staffId,
        public ?ChangeStaffRole $role = null,
    ) {}
}
