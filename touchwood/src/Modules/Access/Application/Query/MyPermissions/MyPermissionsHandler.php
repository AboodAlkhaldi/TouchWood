<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query\MyPermissions;

use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * Asks the authorizer about every declared permission, so the answer can never differ from the
 * checks themselves.
 */
final readonly class MyPermissionsHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private InMemoryPermissionCatalog $catalog,
    ) {}

    /**
     * @return list<HeldPermission>
     */
    public function handle(MyPermissions $query): array
    {
        $held = [];

        foreach ($this->catalog->all() as $permission) {
            $stores = $this->authorizer->storesWith($permission->name);

            if ($stores !== []) {
                $held[] = new HeldPermission($permission->name, $stores === null ? null : array_map(fn (StoreId $store): string => $store->value, $stores));
            }
        }

        return $held;
    }
}
