<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Modules\Inventory\Public\InventoryPermissions;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Reports\ReportKind;
use Modules\Ops\Application\Reports\ReportReaders;
use Modules\Ops\Presentation\Http\Resource\OpsChoicesData;
use Modules\Ops\Presentation\Http\Resource\ReportChoiceData;
use Modules\Ops\Presentation\Http\Resource\StoreChoiceData;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;

/*
| What each of Ops' forms may offer the reader (ops.md §4): Platform's StoreChoices for each form's
| permission - a Super Admin every store, anyone else the stores that are on - and the report kinds they may
| open. Offering is not allowing: every handler still asks for its permission in the store chosen.
*/
final readonly class ChoicesController
{
    public function __construct(
        private StoreChoices $choices,
        private ReportReaders $readers,
    ) {}

    public function __invoke(): JsonResponse
    {
        $superAdmin = $this->readers->person()->isSuperAdmin;
        $reports = [];

        foreach (ReportKind::cases() as $kind) {
            $stores = $this->choices->forJobs(OpsPermissions::REPORT_VIEW);

            if ($kind === ReportKind::Stock) {
                $stock = array_map(static fn (StoreDto $store): string => $store->id, $this->choices->forJobs(InventoryPermissions::STOCK_MANAGE));
                $stores = array_values(array_filter($stores, static fn (StoreDto $store): bool => in_array($store->id, $stock, true)));
            }

            if ($stores !== [] || $superAdmin) {
                $reports[] = new ReportChoiceData($kind, self::stores($stores), $superAdmin);
            }
        }

        return new JsonResponse((new OpsChoicesData(
            self::stores($this->choices->forJobs(OpsPermissions::NOTICE_MANAGE)),
            self::stores($this->choices->forJobs(OpsPermissions::MESSAGE_SEND_ONE)),
            self::stores($this->choices->forJobs(OpsPermissions::MESSAGE_SEND_MANY)),
            self::stores($this->choices->forJobs(OpsPermissions::NEWSLETTER_MANAGE)),
            self::stores($this->choices->forJobs(OpsPermissions::REPORT_DAILY)),
            $superAdmin,
            $reports,
        ))->toArray());
    }

    /**
     * @param  list<StoreDto>  $stores
     * @return list<StoreChoiceData>
     */
    private static function stores(array $stores): array
    {
        $locale = app()->getLocale();

        return array_map(static fn (StoreDto $store): StoreChoiceData => new StoreChoiceData($store->id, $store->code, $store->name->in($locale), $store->isActive), $stores);
    }
}
