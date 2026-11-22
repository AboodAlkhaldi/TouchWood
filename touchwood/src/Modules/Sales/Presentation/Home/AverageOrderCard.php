<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeChange;
use Modules\Platform\Public\Dto\HomeMetric;
use Modules\Platform\Public\Dto\HomeMetricLine;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\SalesPermissions;

/**
 * Home's **Average Order** figure (dashboard.md §2 B, §3.1): Sales ÷ Orders, "—" with no order; its change against
 * the period before's average, and a trend line of each day's own average (0 on a day with no order).
 */
final readonly class AverageOrderCard implements HomeCard
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

        foreach ($stores as $store) {
            [$now, $before] = $this->home->days($store, $scope);
            [$counted, $countedBefore] = $this->home->counts($store, $scope);
            $range = $scope->range($store->timezone);
            $average = SalesHome::average(SalesHome::sales($now, $store->currencyCode), $counted->counted);
            $previous = SalesHome::average(SalesHome::sales($before, $store->currencyCode), $countedBefore->counted);
            $sales = SalesHome::byPoint($now, $range->points(), $range, static fn ($day): int => $day->revenue->minorUnits);
            $orders = SalesHome::byPoint($now, $range->points(), $range, static fn ($day): int => $day->orders);
            $trend = [];

            foreach ($sales as $point => $minor) {
                $trend[] = $orders[$point] === 0 ? 0 : intdiv(2 * $minor + $orders[$point], 2 * $orders[$point]);
            }

            $lines[] = new HomeMetricLine(
                count($stores) > 1 ? SalesHome::name($store) : null,
                $average === null ? null : SalesHome::money($average, $store),
                // No order now: "—", and nothing to compare. No order before: an average now is new.
                $average === null ? null : ($previous === null ? new HomeChange(null) : HomeChange::of($average->minorUnits, $previous->minorUnits)),
                $trend,
            );
        }

        return new HomeCardData([], size: HomeSize::Quarter, metrics: [new HomeMetric((string) __('sales::home.average_order.title'), $lines)]);
    }
}
