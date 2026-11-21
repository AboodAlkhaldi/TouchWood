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
 * individuals and companies, against the period before - shown with Sales' permission (D10), read from Access's
 * `customersRegistered` (§6.3: nothing new in Access). All Stores: a line per store and their total.
 *
 * **No trend line**: Access answers a range's total only, and §6.3 adds nothing to Access, so a line by day
 * would cost a read per day (the builder's pick, listed for the owner).
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
            $now = $this->registered($store->storeId(), $range->from, $range->to);
            $before = $this->registered($store->storeId(), $range->beforeFrom, $range->beforeTo);
            $all += $now;
            $allBefore += $before;
            $lines[] = new HomeMetricLine(count($stores) > 1 ? SalesHome::name($store) : null, HomeValue::count($now), SalesHome::change($now, $before));
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
