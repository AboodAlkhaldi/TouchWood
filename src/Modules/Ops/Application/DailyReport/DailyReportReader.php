<?php

declare(strict_types=1);

namespace Modules\Ops\Application\DailyReport;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\B2B\Public\Contracts\B2BApi;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\SearchSummaryRowDto;
use Modules\Feedback\Public\Contracts\FeedbackApi;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Loyalty\Public\Enums\EntryKind;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\ProductNetSaleDto;
use Modules\Sales\Public\Dto\ProductSaleDto;
use Modules\Sync\Public\Contracts\SyncApi;
use Modules\Sync\Public\Dto\SyncDayDto;
use Shared\Domain\ValueObject\Money;

/**
 * Gathers the daily report (ops.md §1.11) from the modules that own each figure (§2.3) - through their
 * public reads only, never their tables; Ops adds up what they answer and counts nothing of its own.
 *
 * Each store's section uses **its own** day: midnight in the store's time zone until `$until` (when the
 * button is pressed), or midnight to midnight for a past day. "Yesterday, same hours" is the day before at
 * the same clock times; the month to date runs from the 1st at midnight to the same end.
 *
 * **Revenue is net of refunds** (§1.10: "a refund comes off on the day the customer asked for the return"):
 * each day's revenue less the refunds of the returns asked that day and completed - everywhere it is shown:
 * the cards, the three periods, the change, the average order (net revenue ÷ orders that count) and the
 * totals per currency. The refunds stay apart as information; the returns not finished come off only in
 * the second figure, "revenue if the waiting refunds go through".
 *
 * **VAT is net of refunds too** (owner, 2026-10-30): each day's VAT less the goods' VAT of the refunds asked
 * that day and completed (Sales' `refundsVat`); revenue with VAT is net revenue plus net VAT, and the waiting
 * refunds' VAT (`waitingRefundsVat`) comes off only in "revenue with VAT if the waiting refunds go through".
 * VAT is the goods' alone (owner: no VAT on shipping) - Sales' `vat` is taken as it is, never adjusted here.
 *
 * **Transfers waiting are as they stand now**, on any report read after its day too - a past day's, a late
 * worker's, a retry's: Sales keeps no history of them, so the report labels that one figure as current
 * (owner, 2026-11-01).
 */
final readonly class DailyReportReader
{
    /** The sizes sold most, and the searches, a section lists (§1.11 items 4 and 9). */
    public const int TOP = 10;

    /** The page the paged reads are walked with: the most each allows. */
    private const int PAGE = 1000;

    public function __construct(
        private SalesReports $sales,
        private AccessApi $access,
        private B2BApi $b2b,
        private InventoryApi $inventory,
        private SyncApi $sync,
        private PromotionsApi $promotions,
        private LoyaltyApi $loyalty,
        private FeedbackApi $feedback,
        private CatalogApi $catalog,
    ) {}

    /**
     * The report of `$stores`: today's until `$until`, or the whole of `$pastDay` (`Y-m-d`, each store's
     * own date). Two or more stores get the totals per currency after them.
     *
     * @param  non-empty-list<StoreDto>  $stores
     */
    public function report(array $stores, DateTimeImmutable $until, ?string $pastDay, string $timezone): DailyReport
    {
        $sections = array_map(fn (StoreDto $store): StoreDayReport => $this->store($store, $until, $pastDay), $stores);

        return new DailyReport($until, $timezone, count($stores) > 1, $sections, count($stores) > 1 ? $this->totals($sections) : []);
    }

    public function store(StoreDto $store, DateTimeImmutable $until, ?string $pastDay): StoreDayReport
    {
        $zone = new DateTimeZone($store->timezone);
        $id = $store->storeId();

        if ($pastDay === null) {
            $to = $until->setTimezone($zone);
            $from = $to->setTime(0, 0);
        } else {
            $from = new DateTimeImmutable($pastDay.' 00:00:00', $zone);
            $to = $from->modify('+1 day');
        }
        // The same clock times the day before; the 1st of the month at midnight.
        $yesterdayFrom = $from->modify('-1 day');
        $yesterdayTo = $to->modify('-1 day');
        $monthFrom = $from->modify('first day of this month')->setTime(0, 0);

        $counts = $this->sales->counts($id, $from, $to);
        $days = $this->sales->revenueByDay($id, $from, $to);
        $zero = Money::zero($store->currencyCode);
        $revenue = $vat = $shipping = $refunds = $waitingTotal = $waitingVat = $zero;
        $orders = 0;
        $waiting = [];
        foreach ($days as $day) {
            $orders += $day->orders;
            $revenue = $revenue->add($day->revenue)->subtract($day->refunds);
            $vat = $vat->add($day->vat)->subtract($day->refundsVat);
            $shipping = $shipping->add($day->shipping);
            $refunds = $refunds->add($day->refunds);
            $waitingTotal = $waitingTotal->add($day->waitingRefunds);
            $waitingVat = $waitingVat->add($day->waitingRefundsVat);
            array_push($waiting, ...$day->waiting);
        }
        $registered = $this->registered($store, $from, $to);

        // Given: earned and added by hand; spent: redeemed at checkout. The other kinds are listed as
        // they are, only those that happened (owner, 2026-10-30) - Loyalty answers only kinds it has.
        $points = ['given' => 0, 'spent' => 0];
        $otherPoints = [];
        foreach ($this->loyalty->pointsByKind($id, $from, $to) as $kind) {
            match ($kind->kind) {
                EntryKind::Earned, EntryKind::Added => $points['given'] += $kind->points,
                EntryKind::Redeemed => $points['spent'] += abs($kind->points),
                default => $otherPoints[] = $kind,
            };
        }

        [$searches, $noResults, $topSearches, $topNoResults] = $this->searches($store, $from, $to);
        [$topSizes, $categories] = $this->products($store, $from, $to);
        $day = $from->format('Y-m-d');

        return new StoreDayReport(
            store: $store,
            from: $from,
            to: $to,
            pastDay: $pastDay !== null,
            notReadThatDay: $day !== CarbonImmutable::now()->setTimezone($zone)->format('Y-m-d'),
            today: new PeriodFigures($orders, $revenue, $registered['individuals'] + $registered['companies']),
            yesterday: $this->period($store, $yesterdayFrom, $yesterdayTo),
            counts: $counts,
            vat: $vat,
            shipping: $shipping,
            refunds: $refunds,
            waitingRefundsTotal: $waitingTotal,
            waitingRefundsVat: $waitingVat,
            waitingRefunds: $waiting,
            money: $this->sales->moneyOn($id, $from, $from),
            transfersWaiting: $this->sales->transfersWaiting($id),
            topSizes: $topSizes,
            categories: $categories,
            newIndividuals: $registered['individuals'],
            newCompanies: $registered['companies'],
            applications: $this->b2b->applicationCounts($store->id, $from, $to),
            lowStock: $this->inventory->lowStockChanges($id, $from, $to),
            // Sync answers no row for a day that has not begun by `$to`: one that has only just started.
            sync: $this->sync->readsAndProblemsPerDay([$id], $day, $day, $to)[0] ?? new SyncDayDto($store->id, $day, [], [], [], 0, 0, 0, [], true),
            coupons: $this->promotions->couponsUsed($id, $from, $to),
            pointsGiven: $points['given'],
            pointsSpent: $points['spent'],
            otherPoints: array_values(array_filter($otherPoints, static fn ($kind): bool => $kind->entries > 0)),
            feedback: $this->feedback->counts($id, $from, $to),
            searches: $searches,
            searchesWithNoResults: $noResults,
            topSearches: $topSearches,
            topNoResults: $topNoResults,
            monthToDate: $this->period($store, $monthFrom, $to),
        );
    }

    /** Orders that count, revenue without VAT net of the refunds asked in it, and new customers over [from, to). */
    private function period(StoreDto $store, DateTimeImmutable $from, DateTimeImmutable $to): PeriodFigures
    {
        $id = $store->storeId();
        $orders = 0;
        $revenue = Money::zero($store->currencyCode);
        foreach ($this->sales->revenueByDay($id, $from, $to) as $day) {
            $orders += $day->orders;
            $revenue = $revenue->add($day->revenue)->subtract($day->refunds);
        }
        $registered = $this->registered($store, $from, $to);

        return new PeriodFigures($orders, $revenue, $registered['individuals'] + $registered['companies']);
    }

    /**
     * New customers over [from, to). A store whose day has only just started - an all-stores email at the
     * very moment a store east of the base store reaches midnight - has an empty range, which Access refuses
     * to read: nobody registered in it.
     * Every other read answers an empty range with zeros, or Sync with no row (store()), so the section shows
     * zeros and the email goes.
     *
     * @return array{individuals: int, companies: int}
     */
    private function registered(StoreDto $store, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $from < $to ? $this->access->customersRegistered($store->storeId(), $from, $to) : ['individuals' => 0, 'companies' => 0];
    }

    /**
     * The sizes sold most, as sold, named; and revenue per category, **net of refunds** so the table adds up
     * to the report's net revenue (owner, 2026-11-01): every product sold or with a piece in a return asked over
     * [from, to), under its own category, most revenue first. A category's units are the pieces sold (Sales
     * gives no refunded pieces by product); one with nothing but a waiting return has nothing to show, and no row.
     *
     * @return array{list<SizeSold>, list<CategoryRevenue>}
     */
    private function products(StoreDto $store, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<ProductSaleDto> $sold */
        $sold = [];
        $after = null;
        do {
            $page = $this->sales->productSales($store->storeId(), $from, $to, $after, self::PAGE);
            array_push($sold, ...$page->rows);
            $after = $page->next;
        } while ($after !== null);

        /** @var list<ProductNetSaleDto> $net */
        $net = [];
        $after = null;
        do {
            $page = $this->sales->productNetSales($store->storeId(), $from, $to, $after, self::PAGE);
            array_push($net, ...$page->rows);
            $after = $page->next;
        } while ($after !== null);

        if ($sold === [] && $net === []) {
            return [[], []];
        }

        $top = array_slice($sold, 0, self::TOP);
        $variants = $this->catalog->variants(array_map(static fn (ProductSaleDto $row): string => $row->variantId, $top));
        $products = $this->catalog->products(array_values(array_unique([
            ...array_map(static fn (ProductSaleDto $row): string => $row->productId, $top),
            ...array_map(static fn (ProductNetSaleDto $row): string => $row->productId, $net),
        ])));

        $sizes = [];
        foreach ($top as $row) {
            $variant = $variants[$row->variantId] ?? null;
            $product = $products[$row->productId] ?? null;
            $values = $variant === null ? [] : $variant->values;
            $sizes[] = new SizeSold(
                variantId: $row->variantId,
                code: $variant === null ? '' : $variant->code,
                productNameAr: $product === null ? '' : $product->nameAr,
                productNameEn: $product === null ? '' : ($product->nameEn ?? $product->nameAr),
                sizeAr: implode(' · ', array_map(static fn ($value): string => $value->attribute->ar.': '.$value->value->ar, $values)),
                sizeEn: implode(' · ', array_map(static fn ($value): string => $value->attribute->en.': '.$value->value->en, $values)),
                units: $row->units,
                revenue: $row->revenue,
            );
        }

        /** @var array<string, array{?string, int, Money}> $groups */
        $groups = [];
        foreach ($net as $row) {
            $category = ($products[$row->productId] ?? null)?->categoryId;
            $key = $category ?? '';
            $groups[$key] ??= [$category, 0, Money::zero($store->currencyCode)];
            $groups[$key][1] += $row->units;
            $groups[$key][2] = $groups[$key][2]->add($row->revenue)->subtract($row->refunds);
        }
        $groups = array_filter($groups, static fn (array $group): bool => $group[1] > 0 || ! $group[2]->isZero());
        $paths = $this->catalog->categoriesWithPaths(array_values(array_filter(array_map(static fn (array $group): ?string => $group[0], $groups))));

        $categories = [];
        foreach ($groups as [$category, $units, $revenue]) {
            $path = $category === null ? null : ($paths[$category] ?? null);
            $categories[] = new CategoryRevenue(
                categoryId: $category,
                nameAr: $path === null ? '' : implode(' › ', array_map(static fn ($step): string => $step->ar, $path->path)),
                nameEn: $path === null ? '' : implode(' › ', array_map(static fn ($step): string => $step->en, $path->path)),
                units: $units,
                revenue: $revenue,
            );
        }
        usort($categories, static fn (CategoryRevenue $a, CategoryRevenue $b): int => $b->revenue->compareTo($a->revenue));

        return [$sizes, $categories];
    }

    /**
     * Every phrase searched in the store over [from, to): how many searches, how many found nothing, the
     * phrases searched most and those that found nothing most.
     *
     * @return array{int, int, list<SearchSummaryRowDto>, list<SearchSummaryRowDto>}
     */
    private function searches(StoreDto $store, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = [];
        $after = null;
        do {
            $page = $this->catalog->searchSummary($store->storeId(), $from, $to, $after, self::PAGE);
            array_push($rows, ...$page->rows);
            $after = $page->next;
        } while ($after !== null);

        $none = array_values(array_filter($rows, static fn (SearchSummaryRowDto $row): bool => $row->noResults > 0));
        usort($none, static fn (SearchSummaryRowDto $a, SearchSummaryRowDto $b): int => [$b->noResults, $a->query] <=> [$a->noResults, $b->query]);

        return [
            array_sum(array_map(static fn (SearchSummaryRowDto $row): int => $row->searches, $rows)),
            array_sum(array_map(static fn (SearchSummaryRowDto $row): int => $row->noResults, $rows)),
            array_slice($rows, 0, self::TOP),
            array_slice($none, 0, self::TOP),
        ];
    }

    /**
     * One total per currency, never converted (§1.10).
     *
     * @param  list<StoreDayReport>  $sections
     * @return list<CurrencyTotals>
     */
    private function totals(array $sections): array
    {
        $totals = [];
        foreach ($sections as $section) {
            $store = $section->store;
            $code = $store->currencyCode;
            $paymentsIn = Money::zero($code);
            foreach ($section->money->payments as $way) {
                $paymentsIn = $paymentsIn->add($way->amount);
            }
            $current = $totals[$code] ?? null;
            $totals[$code] = new CurrencyTotals(
                currencyCode: $code,
                currencyExponent: $store->currencyExponent,
                symbolAr: $store->currencyAbbreviation->ar,
                symbolEn: $store->currencyAbbreviation->en,
                storeIds: [...($current === null ? [] : $current->storeIds), $store->id],
                today: self::sum($current?->today, $section->today),
                vat: $current === null ? $section->vat : $current->vat->add($section->vat),
                shipping: $current === null ? $section->shipping : $current->shipping->add($section->shipping),
                refunds: $current === null ? $section->refunds : $current->refunds->add($section->refunds),
                paymentsIn: $current === null ? $paymentsIn : $current->paymentsIn->add($paymentsIn),
                monthToDate: self::sum($current?->monthToDate, $section->monthToDate),
            );
        }

        return array_values($totals);
    }

    private static function sum(?PeriodFigures $a, PeriodFigures $b): PeriodFigures
    {
        return $a === null ? $b : new PeriodFigures($a->orders + $b->orders, $a->revenue->add($b->revenue), $a->newCustomers + $b->newCustomers);
    }
}
