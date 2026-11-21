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
use Modules\Sales\Application\Query\Home\SalesHomeReads;
use Modules\Sales\Application\SalesPermissions;

/**
 * Home's **Orders now** (dashboard.md §2 E, D2): four small figures - Waiting (placed, not yet approved or
 * rejected), To Ship (approved, not shipped) and On the Way (shipped, not delivered) as they stand **now**,
 * whatever the period, each leading to the Orders tab that lists them; and Cancelled and Came Back **in the
 * period**, against the period before. All Stores: a line per store and their total (§3.2); a figure then leads to
 * the Orders list over every store.
 */
final readonly class OrdersNowCard implements HomeCard
{
    public function __construct(
        private SalesHome $home,
        private SalesHomeReads $reads,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->home->stores($scope, SalesPermissions::ORDER_VIEW);

        if ($stores === null || $stores === []) {
            return null;
        }

        $many = count($stores) > 1;
        /** @var array<string, list<HomeMetricLine>> $lines */
        $lines = ['waiting' => [], 'to_ship' => [], 'on_the_way' => [], 'ended' => []];
        $totals = ['waiting' => 0, 'to_ship' => 0, 'on_the_way' => 0, 'ended' => 0, 'ended_before' => 0];

        foreach ($stores as $store) {
            $now = $this->reads->ordersNow($store->storeId());
            [$counts, $before] = $this->home->counts($store, $scope);
            $name = $many ? SalesHome::name($store) : null;
            $ended = $counts->cancelled + $counts->cameBack;
            $endedBefore = $before->cancelled + $before->cameBack;
            $lines['waiting'][] = new HomeMetricLine($name, HomeValue::count($now->waiting));
            $lines['to_ship'][] = new HomeMetricLine($name, HomeValue::count($now->toShip));
            $lines['on_the_way'][] = new HomeMetricLine($name, HomeValue::count($now->onTheWay));
            $lines['ended'][] = new HomeMetricLine($name, HomeValue::count($ended), SalesHome::change($ended, $endedBefore));
            $totals['waiting'] += $now->waiting;
            $totals['to_ship'] += $now->toShip;
            $totals['on_the_way'] += $now->onTheWay;
            $totals['ended'] += $ended;
            $totals['ended_before'] += $endedBefore;
        }

        $total = static fn (string $figure, ?int $before = null): ?HomeMetricLine => $many
            ? new HomeMetricLine(null, HomeValue::count($totals[$figure]), $before === null ? null : SalesHome::change($totals[$figure], $before))
            : null;
        $first = $many ? null : $stores[0];

        return new HomeCardData([], size: HomeSize::TwoThirds, metrics: [
            new HomeMetric((string) __('sales::home.orders_now.waiting'), $lines['waiting'], $total('waiting'), SalesHome::ordersHref($first, 'WAITING_APPROVAL')),
            new HomeMetric((string) __('sales::home.orders_now.to_ship'), $lines['to_ship'], $total('to_ship'), SalesHome::ordersHref($first, 'TO_SHIP')),
            new HomeMetric((string) __('sales::home.orders_now.on_the_way'), $lines['on_the_way'], $total('on_the_way'), SalesHome::ordersHref($first, 'SHIPPED')),
            // The Orders list has no tab for these: the figure leads nowhere rather than to a list that disagrees.
            new HomeMetric((string) __('sales::home.orders_now.ended'), $lines['ended'], $total('ended', $totals['ended_before'])),
        ]);
    }
}
