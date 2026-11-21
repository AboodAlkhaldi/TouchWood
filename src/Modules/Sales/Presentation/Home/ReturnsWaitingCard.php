<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeRow;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Sales\Application\Query\Home\SalesHomeReads;
use Modules\Sales\Application\SalesPermissions;

/**
 * Needs Attention's **Returns Waiting** (dashboard.md §2 J, D6): the returns asked and not yet finished, now - a
 * line per store with any. Offered to whoever manages returns (`sales.return.manage`, §5); not shown with none.
 */
final readonly class ReturnsWaitingCard implements HomeCard
{
    public function __construct(
        private SalesHome $home,
        private SalesHomeReads $reads,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->home->stores($scope, SalesPermissions::RETURN_MANAGE);

        if ($stores === null) {
            return null;
        }

        $waiting = 0;
        $rows = [];

        foreach ($stores as $store) {
            $count = $this->reads->returnsWaiting($store->storeId());

            if ($count === 0) {
                continue;
            }

            $waiting += $count;
            $rows[] = new HomeRow(SalesHome::name($store), (string) trans_choice('sales::home.returns_waiting.count', $count, ['count' => $count]));
        }

        return $waiting === 0 ? null : new HomeCardData(
            [new HomeFigure('waiting', $waiting, tone: 'amber')],
            count($stores) > 1 ? $rows : [],
            count($stores) > 1 ? 'stores' : null,
            route('sales.admin.returns', count($stores) > 1 ? [] : ['store' => strtolower($stores[0]->code)], absolute: false),
        );
    }
}
