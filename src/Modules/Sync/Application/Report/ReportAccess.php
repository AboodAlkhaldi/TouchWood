<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\SyncError;
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

    /**
     * As authorize, for an entry or a read looked up by its id: one of a store the person holds neither
     * this permission nor View the Sync Report in is not found - nothing tells them it exists (owner,
     * 2026-10-18).
     *
     * @throws Unauthorized|InvalidSyncInput|SyncError
     */
    public function authorizeFound(string $permission, string $storeId, SyncError $notFound): StoreId
    {
        $holds = function (string $held) use ($storeId): bool {
            $stores = $this->authorizer->storesWith($held);

            return $stores === null || in_array(strtolower($storeId), array_map(static fn (StoreId $store): string => $store->value, $stores), true);
        };

        if (! $holds($permission) && ! $holds(SyncPermissions::REPORT_VIEW)) {
            throw $notFound;
        }

        return $this->authorize($permission, $storeId);
    }
}
