<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Home;

use Modules\Ops\Application\DailyReport\DailyReportPeople;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Contracts\HomeStores;
use Modules\Platform\Public\Dto\HomeAction;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeScope;

/**
 * Home's **Today's Report (PDF)** in the greeting row (dashboard.md §2.1): the daily report of ops.md §1.11 for the
 * store chosen - All Stores: the all-stores report, a Super Admin's only, as on Daily Reports. The button makes it
 * as the Daily Reports page's button does (`MakeDailyReport`, which asks `ops.report.daily` again) and opens its
 * PDF; it is then listed on Daily Reports like any other.
 */
final readonly class TodaysReportCard implements HomeCard
{
    public function __construct(
        private HomeStores $stores,
        private DailyReportPeople $people,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->stores->for($scope, DailyReportPeople::PERMISSION);

        if ($stores === null || $stores === [] || ($scope->isAllStores() && ! $this->people->person()->isSuperAdmin)) {
            return null;
        }

        return new HomeCardData([], action: new HomeAction(
            (string) __('ops::home.report.button'),
            route('ops.admin.daily-reports.make', absolute: false),
            ['store' => $scope->isAllStores() ? '' : $stores[0]->id, 'day' => ''],
            (string) __('ops::home.report.busy'),
            (string) __('ops::home.report.failed'),
        ));
    }
}
