<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomePoint;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeSeries;
use Modules\Platform\Public\Dto\HomeSeriesLine;
use Modules\Platform\Public\Dto\HomeStorePanel;
use Modules\Platform\Public\Dto\HomeValue;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Dto\RevenueDayDto;
use Shared\Domain\ValueObject\Money;

/**
 * Home's **Sales and orders by day** (dashboard.md §2 C): one line chart, the days along the bottom (months for the
 * Year) - Sales, Orders, and the period before's Sales dotted, its n-th day under the period's n-th. One store at
 * a time: All Stores gives a panel per store behind store tabs (D8), since one axis cannot hold three currencies.
 */
final readonly class SalesByDayCard implements HomeCard
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

        $panels = [];

        foreach ($stores as $store) {
            [$now, $before] = $this->home->days($store, $scope);
            $range = $scope->range($store->timezone);
            $template = SalesHome::money(Money::zero($store->currencyCode), $store)->money;
            $sales = SalesHome::byPoint($now, $range->points(), $range, static fn (RevenueDayDto $day): int => $day->revenue->minorUnits);
            $orders = SalesHome::byPoint($now, $range->points(), $range, static fn (RevenueDayDto $day): int => $day->orders);
            $previous = array_values(SalesHome::byPoint($before, $range->beforePoints(), $range, static fn (RevenueDayDto $day): int => $day->revenue->minorUnits));
            $points = [];
            $index = 0;

            foreach ($sales as $at => $minor) {
                $points[] = new HomePoint((string) $at, ['sales' => $minor, 'orders' => $orders[$at], 'before' => $previous[$index] ?? null]);
                $index++;
            }

            $panels[] = new HomeStorePanel(strtolower($store->code), SalesHome::name($store), new HomeSeries([
                new HomeSeriesLine('sales', (string) __('sales::home.by_day.sales'), HomeValue::MONEY, money: $template),
                new HomeSeriesLine('orders', (string) __('sales::home.by_day.orders'), HomeValue::COUNT),
                new HomeSeriesLine('before', (string) __('sales::home.by_day.before'), HomeValue::MONEY, dotted: true, money: $template),
            ], $points, $range->byMonth));
        }

        return new HomeCardData([], size: HomeSize::TwoThirds, panels: $panels);
    }
}
