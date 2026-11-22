<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Modules\Inventory\Public\InventoryPermissions;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Reports\ReportKind;
use Modules\Ops\Application\Reports\ReportReaders;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * The reports this reader may open (ops.md §4.1, §4.6): each kind with the stores they may read it for, in
 * `ReportKind`'s order - Stock only where they hold `inventory.stock.manage` too - and every store's together a
 * Super Admin's alone. Offering is not allowing: the handler still checks the store chosen.
 *
 * Shared by `ops.admin.choices` and the Reports page (§4.15 O8). Taken as an action's argument, never held by a
 * controller: it asks who is acting (lesson 171).
 */
final readonly class ReportChoices
{
    public function __construct(
        private StoreChoices $choices,
        private ReportReaders $readers,
    ) {}

    /**
     * The kinds offered, and the time zone each offered store's days are read in, by the store's code as the page's
     * address names it - `all` the base store's, the zone
     * an every-store report names its moment in (§1.10).
     *
     * @return array{superAdmin: bool, reports: list<ReportChoiceData>, zones: array<string, string>}
     *
     * @throws Unauthorized when the reader is not an active staff member
     */
    public function offered(): array
    {
        $superAdmin = $this->readers->person()->isSuperAdmin;
        $viewable = $this->choices->forJobs(OpsPermissions::REPORT_VIEW);
        $reports = [];
        $zones = [];

        foreach (ReportKind::cases() as $kind) {
            $stores = $viewable;

            if ($kind === ReportKind::Stock) {
                $stock = array_map(static fn (StoreDto $store): string => $store->id, $this->choices->forJobs(InventoryPermissions::STOCK_MANAGE));
                $stores = array_values(array_filter($stores, static fn (StoreDto $store): bool => in_array($store->id, $stock, true)));
            }

            if ($stores !== [] || $superAdmin) {
                $reports[] = new ReportChoiceData($kind, StoreChoiceData::listOf($stores), $superAdmin);
            }
        }

        foreach ($viewable as $store) {
            $zones[$store->code] = $store->timezone;
        }

        if ($superAdmin) {
            $zones[ReportReaders::ALL] = $this->choices->baseTimezone();
        }

        return ['superAdmin' => $superAdmin, 'reports' => $reports, 'zones' => $zones];
    }
}
