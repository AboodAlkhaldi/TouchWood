<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every report read and action shares (sync.md §3): the permission in that store - a malformed
 * store id is no store the person holds anything in - and a store that exists. An action on an entry or
 * a read looks that up first, to know its store (as Pricing finds a sale first).
 */
final readonly class ReportAccess
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * @throws Unauthorized|InvalidSyncInput
     */
    public function authorize(string $permission, string $storeId): StoreId
    {
        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized($permission);
        }

        $this->authorizer->authorize($permission, PermissionScope::store($store));

        if ($this->platform->store($store) === null) {
            throw new InvalidSyncInput('store');
        }

        return $store;
    }
}
