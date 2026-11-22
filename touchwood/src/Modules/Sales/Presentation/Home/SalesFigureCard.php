<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeMetric;
use Modules\Platform\Public\Dto\HomeMetricLine;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Dto\RevenueDayDto;

/**
 * Home's **Sales** figure (dashboard.md §2 B, §3.1): what customers pay for the goods after the coupon and the
 * points, without VAT and without shipping, of the orders placed in the period that count - its change against
 * the period before, a small trend line by day (by month for the Year), and the refunds under it in small text,
 * **not taken off** (D7). All Stores: a line per store, each in its own currency (§3.2), no total.
 */
final readonly class SalesFigureCard implements HomeCard
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
            $range = $scope->range($store->timezone);
            $sales = SalesHome::sales($now, $store->currencyCode);
            $refunds = SalesHome::refunds($now, $store->currencyCode);
            $lines[] = new HomeMetricLine(
                count($stores) > 1 ? SalesHome::name($store) : null,
                SalesHome::money($sales, $store),
                SalesHome::change($sales->minorUnits, SalesHome::sales($before, $store->currencyCode)->minorUnits),
                array_values(SalesHome::byPoint($now, $range->points(), $range, static fn (RevenueDayDto $day): int => $day->revenue->minorUnits)),
                $refunds->isZero() ? null : (string) __('sales::home.sales.refunds', ['amount' => SalesHome::money($refunds, $store)->money->text ?? '']),
            );
        }

        return new HomeCardData([], size: HomeSize::Quarter, metrics: [new HomeMetric((string) __('sales::home.sales.title'), $lines)]);
    }
}
