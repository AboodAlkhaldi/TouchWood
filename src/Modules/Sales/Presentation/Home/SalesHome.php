<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Home;

use Modules\Platform\Public\Contracts\HomeStores;
use Modules\Platform\Public\Dto\HomeChange;
use Modules\Platform\Public\Dto\HomeRange;
use Modules\Platform\Public\Dto\HomeScope;
use Modules\Platform\Public\Dto\HomeValue;
use Modules\Platform\Public\Dto\MediaUrlsDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\RevenueDayDto;
use Modules\Sales\Public\Dto\SalesCountsDto;
use Shared\Domain\ValueObject\Money;

/**
 * What Sales' Home sections share (dashboard.md §3): the stores a scope speaks for, and each store's period read
 * once per request, however many sections ask - the day-by-day Sales and the order counts, of the period and of
 * the one before. Bound scoped: it keeps what it read for the request answering, never longer.
 *
 * Every figure is read in the store's own day (its time zone) and never counts a test order, as the daily report
 * reads them (ops.md §1.11), through the same reads (`SalesReports`).
 */
final class SalesHome
{
    /** @var array<string, array{list<RevenueDayDto>, list<RevenueDayDto>}> */
    private array $days = [];

    /** @var array<string, array{SalesCountsDto, SalesCountsDto}> */
    private array $counts = [];

    public function __construct(
        private readonly HomeStores $stores,
        private readonly SalesReports $reports,
    ) {}

    /**
     * The stores the scope speaks for, the base store first (D8) - Platform's one answer for every card
     * (`HomeStores`); null when a section holding `$permission` must read none.
     *
     * @return list<StoreDto>|null
     */
    public function stores(HomeScope $scope, string $permission): ?array
    {
        return $this->stores->for($scope, $permission);
    }

    /**
     * The store's revenue day by day, of the period and of the one before (`SalesReports::revenueByDay`).
     *
     * @return array{list<RevenueDayDto>, list<RevenueDayDto>}
     */
    public function days(StoreDto $store, HomeScope $scope): array
    {
        $range = $scope->range($store->timezone);

        return $this->days[self::key($store, $scope)] ??= [
            $this->reports->revenueByDay($store->storeId(), $range->from, $range->to),
            $this->reports->revenueByDay($store->storeId(), $range->beforeFrom, $range->beforeTo),
        ];
    }

    /**
     * The store's orders of the period and of the one before (`SalesReports::counts`).
     *
     * @return array{SalesCountsDto, SalesCountsDto}
     */
    public function counts(StoreDto $store, HomeScope $scope): array
    {
        $range = $scope->range($store->timezone);

        return $this->counts[self::key($store, $scope)] ??= [
            $this->reports->counts($store->storeId(), $range->from, $range->to),
            $this->reports->counts($store->storeId(), $range->beforeFrom, $range->beforeTo),
        ];
    }

    /**
     * Sales (dashboard.md §3.1): the revenue of the days, refunds **not** taken off (D7).
     *
     * @param  list<RevenueDayDto>  $days
     */
    public static function sales(array $days, string $currency): Money
    {
        $sum = Money::zero($currency);

        foreach ($days as $day) {
            $sum = $sum->add($day->revenue);
        }

        return $sum;
    }

    /**
     * The refunds of the days, shown under Sales apart (D7).
     *
     * @param  list<RevenueDayDto>  $days
     */
    public static function refunds(array $days, string $currency): Money
    {
        $sum = Money::zero($currency);

        foreach ($days as $day) {
            $sum = $sum->add($day->refunds);
        }

        return $sum;
    }

    /**
     * A figure of the days at each point of the period - a day, or a month for the Year - in its order.
     *
     * @param  list<RevenueDayDto>  $days
     * @param  list<string>  $points
     * @param  callable(RevenueDayDto): int  $figure
     * @return array<string, int>
     */
    public static function byPoint(array $days, array $points, HomeRange $range, callable $figure): array
    {
        $values = array_fill_keys($points, 0);

        foreach ($days as $day) {
            $point = $range->pointOf($day->day);

            if (array_key_exists($point, $values)) {
                $values[$point] += $figure($day);
            }
        }

        return $values;
    }

    /** Sales ÷ Orders in the smallest unit, rounded half up; null with no order ("—"). */
    public static function average(Money $sales, int $orders): ?Money
    {
        return $orders === 0 ? null : Money::of(intdiv(2 * $sales->minorUnits + $orders, 2 * $orders), $sales->currencyCode);
    }

    public static function money(Money $money, StoreDto $store): HomeValue
    {
        return HomeValue::money($money, $store, self::locale());
    }

    public static function change(int $now, int $before): ?HomeChange
    {
        return HomeChange::of($now, $before);
    }

    /** @return 'ar'|'en' */
    public static function locale(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'ar';
    }

    public static function name(StoreDto $store): string
    {
        return $store->name->in(self::locale());
    }

    /** A public photo's thumb, the lightest format first; null until its sizes are made. */
    public static function thumb(?MediaUrlsDto $urls): ?string
    {
        $sizes = $urls === null ? [] : ($urls->variants[MediaSize::Thumb->slug()] ?? []);

        foreach ([ImageFormat::Avif, ImageFormat::Webp, ImageFormat::Jpeg] as $format) {
            $url = $sizes[$format->extension()] ?? null;

            if (is_string($url)) {
                return $url;
            }
        }

        return null;
    }

    /** The Orders list on one of its tabs, for one store - or every store the reader has, for none. */
    public static function ordersHref(?StoreDto $store, string $tab): string
    {
        return route('sales.admin.orders', array_filter(['store' => $store === null ? null : strtolower($store->code), 'tab' => $tab]), absolute: false);
    }

    private static function key(StoreDto $store, HomeScope $scope): string
    {
        return strtolower($store->id).'|'.$scope->period->value.'|'.$scope->at->format('U.u');
    }
}
