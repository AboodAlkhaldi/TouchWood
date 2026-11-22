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
    /** What a request names for every store's report - a super admin's alone. */
    public const string ALL = 'all';

    public function __construct(
        private ActorContext $actors,
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
     * The report a request names, and its store - `all` for every store's - with the permissions its handler
     * checks there. Nothing is authorized here: the handler does it, then asks `stores()`.
     *
     * @throws Unauthorized|InvalidOpsInput
     */
    public function ask(string $kind, string $store): ReportAsk
    {
        $kind = ReportKind::tryFrom($kind) ?? throw new InvalidOpsInput('report', 'not a report');
        $person = $this->person();
        $permissions = $kind === ReportKind::Stock ? [OpsPermissions::REPORT_VIEW, InventoryPermissions::STOCK_MANAGE] : [OpsPermissions::REPORT_VIEW];

        if ($store === self::ALL) {
            return new ReportAsk($kind, $person, null, $permissions);
        }

        $id = strtolower(trim($store));
        $found = (Ulids::valid($id) ? $this->platform->store(StoreId::fromString($id)) : null) ?? throw new InvalidOpsInput('store', 'not a store');

        return new ReportAsk($kind, $person, $found, $permissions);
    }

    /**
     * The stores of the report, once its permissions are checked: the one named, or every store - on and
     * off - for a super admin alone.
     *
     * @return non-empty-list<StoreDto>
     *
     * @throws Unauthorized|InvalidOpsInput
     */
    public function stores(ReportAsk $ask): array
    {
        if ($ask->store !== null) {
            return [$ask->store];
        }
        if (! $ask->person->isSuperAdmin) {
            throw new Unauthorized(OpsPermissions::REPORT_VIEW);
        }
        $stores = $this->platform->allStores();

        return $stores !== [] ? $stores : throw new InvalidOpsInput('store', 'no store');
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
