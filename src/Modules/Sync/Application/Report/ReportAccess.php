<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use InvalidArgumentException;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Exception\InvalidSyncInput;
use Modules\Sync\Domain\Exception\StoreOff;
use Modules\Sync\Domain\Exception\SyncError;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What every report read and action shares (sync.md §3): the permission in that store - a malformed
 * store id is no store the person holds anything in - a store that exists, and, off, one only whoever
 * may switch stores works in (`StoreOff`). An action on an entry or
 * a read looks that up first, to know its store (as Pricing finds a sale first).
 */
final readonly class ReportAccess
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
    ) {}

    /**
     * @param  SyncError|null  $notFound  for an entry or a read looked up by its id: one of a store the
     *                                    person holds neither this permission nor View the Sync Report in
     *                                    is not found - nothing tells them it exists (owner, 2026-10-18)
     *
     * @throws Unauthorized|InvalidSyncInput|StoreOff|SyncError
     */
    public function authorize(string $permission, string $storeId, ?SyncError $notFound = null): StoreId
    {
        if ($notFound !== null && ! $this->holds($permission, $storeId) && ! $this->holds(SyncPermissions::REPORT_VIEW, $storeId)) {
            throw $notFound;
        }

        try {
            $store = StoreId::fromString($storeId);
        } catch (InvalidArgumentException) {
            throw new Unauthorized($permission);
        }

        $this->authorizer->authorize($permission, PermissionScope::store($store));

        $found = $this->platform->store($store) ?? throw new InvalidSyncInput('store');

        // An off store is offered only to whoever may switch stores; the reads and actions refuse
        // everyone else (pricing.md §4.3.1 Q1, shared by every stage 5 screen).
        if (! $found->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new StoreOff;
        }

        return $store;
    }

    /** Whether the person holds the permission in the store, or in every store. */
    private function holds(string $permission, string $storeId): bool
    {
        $stores = $this->authorizer->storesWith($permission);

        return $stores === null || in_array(strtolower($storeId), array_map(static fn (StoreId $store): string => $store->value, $stores), true);
    }
}
