<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeMetric;
use Modules\Platform\Public\Dto\HomeMetricLine;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeValue;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Dto\RevenueDayDto;

/**
 * Home's **Orders** figure (dashboard.md §2 B, §3.1): the orders placed in the period that count (`counts`'s
 * `counted`), against the period before, with a trend line of them by day. All Stores: a line per store and their
 * total (§3.2).
 */
final readonly class OrdersFigureCard implements HomeCard
{
    public function __construct(
        private SalesHome $home,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->home->stores($scope, SalesPermissions::ORDER_VIEW);

        if ($stores === null || $stores === []) {
            return null;
        }

        $lines = [];
        [$all, $allBefore] = [0, 0];

        foreach ($stores as $store) {
            [$now, $before] = $this->home->counts($store, $scope);
            [$days] = $this->home->days($store, $scope);
            $range = $scope->range($store->timezone);
            $all += $now->counted;
            $allBefore += $before->counted;
            $lines[] = new HomeMetricLine(
                count($stores) > 1 ? SalesHome::name($store) : null,
                HomeValue::count($now->counted),
                SalesHome::change($now->counted, $before->counted),
                array_values(SalesHome::byPoint($days, $range->points(), $range, static fn (RevenueDayDto $day): int => $day->orders)),
            );
        }

        $total = count($stores) > 1 ? new HomeMetricLine(null, HomeValue::count($all), SalesHome::change($all, $allBefore)) : null;

        return new HomeCardData([], size: HomeSize::Quarter, metrics: [new HomeMetric((string) __('sales::home.orders.title'), $lines, $total)]);
    }
}
