<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\HomeStores;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Public\Contracts\SyncApi;

/**
 * Needs Attention's **Sync Problems** (dashboard.md §2 J, D6): the Sync Report's entries **open now** - the count
 * beside its menu entry, over the stores the card speaks for - and those **opened in the period**, from
 * `readsAndProblemsPerDay` (§6.7), each store's days its own. For whoever reads the Sync Report
 * (`sync.report.view`, §5); not shown with neither.
 */
final readonly class SyncProblemsCard implements HomeCard
{
    public function __construct(
        private HomeStores $stores,
        private SyncLog $log,
        private SyncApi $sync,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->stores->for($scope, SyncPermissions::REPORT_VIEW);

        if ($stores === null || $stores === []) {
            return null;
        }

        $open = $this->log->openCountIn(array_map(static fn (StoreDto $store) => $store->storeId(), $stores));
        $opened = 0;

        foreach ($stores as $store) {
            $range = $scope->range($store->timezone);

            if ($range->from >= $range->to) {
                continue;
            }

            foreach ($this->sync->readsAndProblemsPerDay([$store->storeId()], $range->from->format('Y-m-d'), $range->to->format('Y-m-d'), $range->to) as $day) {
                $opened += array_sum($day->problemsOpened);
            }
        }

        if ($open === 0 && $opened === 0) {
            return null;
        }

        $href = route('sync.admin.report', count($stores) > 1 ? [] : ['store' => strtolower($stores[0]->code)], absolute: false);

        return new HomeCardData([
            new HomeFigure('open', $open, href: $href, tone: 'amber'),
            new HomeFigure('opened', $opened),
        ], href: $href);
    }
}
