<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateRole;

/**
 * Edits a saved role: the change reaches everyone who holds it (Access spec §1.5). Only the
 * given fields change. A personal role is changed from its staff member's page instead.
 */
final readonly class UpdateRole
{
    /**
     * @param  list<string>|null  $permissions  the whole new list of actions
     */
    public function __construct(
        public string $roleId,
        public ?string $nameAr = null,
        public ?string $nameEn = null,
        public ?array $permissions = null,
    ) {}
}
