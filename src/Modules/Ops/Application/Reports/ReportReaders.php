<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Dto\StaffDto;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Inventory\Public\InventoryPermissions;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Who reads a report, and which stores it covers (ops.md §1.10, §3): `ops.report.view` in a store they
 * may view - the Stock report `inventory.stock.manage` there too - and every store's report a super
 * admin's alone ("a super admin: one or all"). Every store is **on and off**, the off ones marked
 * (owner, 2026-10-30).
 */
final readonly class ReportReaders
{
    public function __construct(
        private ActorContext $actors,
        private Authorizer $authorizer,
        private AccessApi $access,
        private PlatformApi $platform,
    ) {}

    /**
     * The staff member asking, active: a report is read in the panel only.
     *
     * @throws Unauthorized
     */
    public function person(): StaffDto
    {
        $actor = $this->actors->current();
        $staff = $actor->type === ActorType::Staff && $actor->id !== null ? $this->access->staff($actor->id) : null;

        return $staff !== null && $staff->status === StaffStatus::Active ? $staff : throw new Unauthorized(OpsPermissions::REPORT_VIEW);
    }

    /**
     * The stores of the report: the one named, or every store when none is.
     *
     * @return non-empty-list<StoreDto>
     *
     * @throws Unauthorized|InvalidOpsInput
     */
    public function stores(ReportKind $kind, ?string $storeId, StaffDto $person): array
    {
        $permissions = $kind === ReportKind::Stock ? [OpsPermissions::REPORT_VIEW, InventoryPermissions::STOCK_MANAGE] : [OpsPermissions::REPORT_VIEW];

        if ($storeId === null) {
            foreach ($permissions as $permission) {
                $this->authorizer->authorize($permission, PermissionScope::allStores());
            }
            if (! $person->isSuperAdmin) {
                throw new Unauthorized(OpsPermissions::REPORT_VIEW);
            }
            $stores = $this->platform->allStores();

            return $stores !== [] ? $stores : throw new InvalidOpsInput('store', 'no store');
        }

        $id = strtolower(trim($storeId));
        $store = (Ulids::valid($id) ? $this->platform->store(StoreId::fromString($id)) : null) ?? throw new InvalidOpsInput('store', 'not a store');
        foreach ($permissions as $permission) {
            $this->authorizer->authorize($permission, PermissionScope::store($store->storeId()));
        }

        return [$store];
    }

    /** The time zone a report of every store names its moment in: the base store's (ops.md §1.11). */
    public function baseZone(): string
    {
        foreach ($this->platform->allStores() as $store) {
            if ($store->isBase) {
                return $store->timezone;
            }
        }

        throw new InvalidOpsInput('store', 'no base store');
    }
}
