<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyPermissions;

final readonly class HeldPermission
{
    /**
     * @param  list<string>|null  $storeIds  null for every store (or a store-free permission)
     */
    public function __construct(
        public string $name,
        public ?array $storeIds,
    ) {}
}
