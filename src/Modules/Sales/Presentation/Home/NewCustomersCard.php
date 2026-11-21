<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Access\Public\Contracts\AccessApi;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeMetric;
use Modules\Platform\Public\Dto\HomeMetricLine;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeValue;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\SalesPermissions;
use Shared\Domain\ValueObject\StoreId;

/**
 * Home's **New Customers** figure (dashboard.md §2 B, §3.1): the accounts registered in the store in the period,
 * individuals and companies, against the period before - shown with Sales' permission (D10). The period by day
 * from Access's `customersRegisteredByDay` (its figure and its small trend line, §2 B; the owner, 2026-11-21,
 * amending §6.3), the period before from `customersRegistered`: two reads a store. All Stores: a line per store
 * and their total.
 */
final readonly class NewCustomersCard implements HomeCard
{
    public function __construct(
        private SalesHome $home,
        private AccessApi $access,
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
            $range = $scope->range($store->timezone);
            $trend = array_fill_keys($range->points(), 0);

            // An empty range (the very first moment of a day) is refused by Access: nobody registered in it.
            if ($range->from < $range->to) {
                foreach ($this->access->customersRegisteredByDay($store->storeId(), $range->from, $range->to, $store->timezone) as $day => $count) {
                    $point = $range->pointOf($day);

                    if (array_key_exists($point, $trend)) {
                        $trend[$point] += $count;
                    }
                }
            }

            $now = array_sum($trend);
            $before = $this->registered($store->storeId(), $range->beforeFrom, $range->beforeTo);
            $all += $now;
            $allBefore += $before;
            $lines[] = new HomeMetricLine(count($stores) > 1 ? SalesHome::name($store) : null, HomeValue::count($now), SalesHome::change($now, $before), array_values($trend));
        }

        $total = count($stores) > 1 ? new HomeMetricLine(null, HomeValue::count($all), SalesHome::change($all, $allBefore)) : null;

        return new HomeCardData([], size: HomeSize::Quarter, metrics: [new HomeMetric((string) __('sales::home.new_customers.title'), $lines, $total)]);
    }

    /** Access refuses an empty range (the very first moment of a day): nobody registered in it. */
    private function registered(StoreId $store, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($from >= $to) {
            return 0;
        }

        $counts = $this->access->customersRegistered($store, $from, $to);

        return $counts['individuals'] + $counts['companies'];
    }
}
