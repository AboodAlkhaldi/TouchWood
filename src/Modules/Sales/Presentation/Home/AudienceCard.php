<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeSplit;
use Modules\Platform\Public\Dto\HomeSplitPart;
use Modules\Platform\Public\Dto\HomeStorePanel;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\Query\Home\SalesHomeReads;
use Modules\Sales\Application\SalesPermissions;

/**
 * Home's **Individuals and companies** (dashboard.md §2 D; the owner: a ring, not a line): each one's share of
 * Sales, the total in the ring's middle, and under it each one's Sales and its change against the period before.
 * One store at a time (D8).
 */
final readonly class AudienceCard implements HomeCard
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

        $panels = [];

        foreach ($stores as $store) {
            $range = $scope->range($store->timezone);
            $now = $this->reads->byAudience($store->storeId(), $store->currencyCode, $range->from, $range->to);
            $before = $this->reads->byAudience($store->storeId(), $store->currencyCode, $range->beforeFrom, $range->beforeTo);
            [$individuals, $companies] = HomeSplit::shares([$now->individuals->minorUnits, $now->companies->minorUnits]);

            $panels[] = new HomeStorePanel(strtolower($store->code), SalesHome::name($store), splits: [new HomeSplit(
                (string) __('sales::home.audience.title'),
                HomeSplit::RING,
                [
                    new HomeSplitPart((string) __('sales::home.audience.individuals'), SalesHome::money($now->individuals, $store), $individuals, SalesHome::change($now->individuals->minorUnits, $before->individuals->minorUnits)),
                    new HomeSplitPart((string) __('sales::home.audience.companies'), SalesHome::money($now->companies, $store), $companies, SalesHome::change($now->companies->minorUnits, $before->companies->minorUnits)),
                ],
                SalesHome::money($now->individuals->add($now->companies), $store),
            )]);
        }

        return new HomeCardData([], size: HomeSize::Third, panels: $panels);
    }
}
