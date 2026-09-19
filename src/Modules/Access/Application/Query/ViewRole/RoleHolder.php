<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\ViewRole;

final readonly class RoleHolder
{
    /**
     * @param  list<string>|null  $storeIds  the staff member's stores; null for all stores
     */
    public function __construct(
        public string $staffId,
        public string $firstName,
        public string $lastName,
        public ?array $storeIds,
    ) {}
}
