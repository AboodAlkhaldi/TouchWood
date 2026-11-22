<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Platform\Public\Contracts\HomeCard;
use Modules\Platform\Public\Dto\HomeCardData;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeSplit;
use Modules\Platform\Public\Dto\HomeSplitPart;
use Modules\Platform\Public\Dto\HomeStorePanel;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\HomeSize;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Contracts\SalesReports;
use Shared\Domain\ValueObject\Money;

/**
 * Home's **two split bars** (dashboard.md §2 I): **Sales by category** - each top-level category's Sales, the
 * smallest past the fifth joined as "Other" (D5), a product with no category among them - and **money received by
 * way**: the period's payments by way (online, transfer, other), as the daily report's Money section reads them
 * (`SalesReports::moneyOn`), voided ones never. One store at a time (D8).
 *
 * A category's Sales are `productNetSales`'s goods as sold, refunds **not** taken off, so the bar adds up to the
 * Sales figure (D7).
 */
final readonly class DistributionCard implements HomeCard
{
    /** The categories named before the rest are joined as Other (D5). */
    public const int NAMED = 5;

    /** The most `productNetSales` gives at once. */
    private const int PAGE = 1000;

    public function __construct(
        private SalesHome $home,
        private SalesReports $reports,
        private CatalogApi $catalog,
    ) {}

    public function data(HomeScope $scope): ?HomeCardData
    {
        $stores = $this->home->stores($scope, SalesPermissions::ORDER_VIEW);

        if ($stores === null || $stores === []) {
            return null;
        }

        return new HomeCardData([], size: HomeSize::Full, panels: array_map(fn (StoreDto $store): HomeStorePanel => new HomeStorePanel(
            strtolower($store->code),
            SalesHome::name($store),
            splits: [$this->categories($store, $scope), $this->ways($store, $scope)],
        ), $stores));
    }

    private function categories(StoreDto $store, HomeScope $scope): HomeSplit
    {
        $range = $scope->range($store->timezone);
        /** @var array<string, int> $byProduct */
        $byProduct = [];
        $after = null;

        do {
            $page = $this->reports->productNetSales($store->storeId(), $range->from, $range->to, $after, self::PAGE);

            foreach ($page->rows as $row) {
                $byProduct[$row->productId] = ($byProduct[$row->productId] ?? 0) + $row->revenue->minorUnits;
            }

            $after = $page->next;
        } while ($after !== null);

        $byProduct = array_filter($byProduct, static fn (int $minor): bool => $minor !== 0);
        $products = $byProduct === [] ? [] : $this->catalog->products(array_map(strval(...), array_keys($byProduct)));
        $categories = array_values(array_unique(array_filter(array_map(static fn ($product): ?string => $product->categoryId, $products))));
        $paths = $categories === [] ? [] : $this->catalog->categoriesWithPaths($categories);
        $locale = SalesHome::locale();
        /** @var array<string, array{string, int}> $tops */
        $tops = [];
        $other = 0;

        foreach ($byProduct as $productId => $minor) {
            $category = ($products[strtolower((string) $productId)] ?? null)?->categoryId;
            $top = $category === null ? null : (($paths[$category] ?? null)?->path[0] ?? null);

            if ($top === null) {
                $other += $minor;

                continue;
            }

            $key = $top->ar.'|'.$top->en;
            $tops[$key] ??= [$top->in($locale), 0];
            $tops[$key][1] += $minor;
        }

        usort($tops, static fn (array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);

        foreach (array_slice($tops, self::NAMED) as [, $minor]) {
            $other += $minor;
        }

        $parts = array_slice($tops, 0, self::NAMED);

        if ($other !== 0) {
            $parts[] = [(string) __('sales::home.distribution.other'), $other];
        }

        return $this->split((string) __('sales::home.distribution.categories'), $parts, $store);
    }

    private function ways(StoreDto $store, HomeScope $scope): HomeSplit
    {
        $range = $scope->range($store->timezone);
        $parts = [];

        if ($range->from < $range->to) {
            // The days the period touches: its last is the day of its last moment.
            $days = $this->reports->moneyOn($store->storeId(), $range->from, $range->to->modify('-1 microsecond'));
            /** @var array<string, int> $byWay */
            $byWay = [];

            foreach ($days->payments as $way) {
                $byWay[$way->way] = ($byWay[$way->way] ?? 0) + $way->amount->minorUnits;
            }

            arsort($byWay);

            foreach ($byWay as $way => $minor) {
                $parts[] = [(string) __("sales::admin_orders.money_way.{$way}"), $minor];
            }
        }

        return $this->split((string) __('sales::home.distribution.ways'), $parts, $store);
    }

    /** @param  list<array{string, int}>  $parts */
    private function split(string $label, array $parts, StoreDto $store): HomeSplit
    {
        $shares = HomeSplit::shares(array_map(static fn (array $part): int => $part[1], $parts));
        $money = static fn (int $minor): Money => Money::of($minor, $store->currencyCode);

        return new HomeSplit(
            $label,
            HomeSplit::BAR,
            array_map(static fn (array $part, float $share): HomeSplitPart => new HomeSplitPart($part[0], SalesHome::money($money($part[1]), $store), $share), $parts, $shares),
            SalesHome::money($money(array_sum(array_map(static fn (array $part): int => $part[1], $parts))), $store),
        );
    }
}
