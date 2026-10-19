<?php

declare(strict_types=1);

namespace Tests\Modules\Promotions\Support;

use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Pricing\Public\Enums\PriceKind;
use Modules\Promotions\Application\Coupon\Ceiling;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CallerPermission;
use Modules\Sales\Application\SalesPermissions;
use RuntimeException;
use Shared\Domain\ValueObject\Money;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Throwable;

/**
 * Promotions' tests calling it as Sales will (promotions.md §2.1). Staff cannot make coupons until
 * step 3, so a coupon is written straight into its table here, with the database's own rules holding
 * it; a cart's prices are built as Pricing answers them, as Sales passes them in.
 */
final class PromotionsFixtures
{
    /** Sales's placing an order: every customer, per store (sales.md §3). */
    public const string PLACE = SalesPermissions::ORDER_PLACE;

    /** Sales's staff edit: a role's job, per store (sales.md §3). */
    public const string EDIT = SalesPermissions::ORDER_EDIT;

    /** Sales's system work: reserved and store-free (sales.md §3) — never a caller's permission. */
    public const string SYSTEM = SalesPermissions::SYSTEM_RUN;

    public static function placing(): CallerPermission
    {
        return new CallerPermission('sales', self::PLACE);
    }

    public static function editing(): CallerPermission
    {
        return new CallerPermission('sales', self::EDIT);
    }

    public static function orderId(): string
    {
        return strtolower((string) Str::ulid());
    }

    /**
     * The error the call throws, of that class — for asserting what it carries; anything else, or
     * nothing, fails the test.
     *
     * @template T of Throwable
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public static function thrown(Closure $call, string $class): Throwable
    {
        try {
            $call();
        } catch (Throwable $error) {
            if ($error instanceof $class) {
                return $error;
            }

            throw $error;
        }

        throw new RuntimeException("Nothing was thrown; expected {$class}.");
    }

    public static function api(): PromotionsApi
    {
        return app(PromotionsApi::class);
    }

    public static function currency(string $storeCode = 'sa'): string
    {
        return app(PlatformApi::class)->storeByCode($storeCode)->currencyCode
            ?? throw new RuntimeException("Store {$storeCode} is not seeded.");
    }

    public static function money(int $minor, string $storeCode = 'sa'): Money
    {
        return Money::of($minor, self::currency($storeCode));
    }

    /** The store's discount ceiling (Pricing's setting), as an admin would save it. */
    public static function ceiling(int $percent, string $storeCode = 'sa'): void
    {
        Fx::asSystem(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(Ceiling::KEY, $storeCode, $percent)));
    }

    /**
     * A coupon of the store: 15% for everyone, from yesterday, no end, unless told otherwise.
     *
     * @param  array<string, mixed>  $with  columns of promotions.coupons
     * @param  list<array{0: string, 1: string, 2: string}>  $rules  [kind, product|category|brand, id]
     */
    public static function coupon(string $code = 'SUMMER-15', string $storeCode = 'sa', array $with = [], array $rules = []): string
    {
        $id = strtolower((string) Str::ulid());
        $now = CarbonImmutable::now();

        DB::table('promotions.coupons')->insert([
            'id' => $id,
            'store_id' => Fx::storeId($storeCode),
            'code' => $code,
            'name_ar' => 'كوبون الصيف',
            'name_en' => 'Summer coupon',
            'eligibility' => 'PUBLIC',
            'discount_kind' => 'PERCENT',
            'percent' => 15,
            'cap_minor' => null,
            'amount_minor' => null,
            'currency_code' => self::currency($storeCode),
            'minimum_minor' => null,
            'starts_at' => $now->subDay(),
            'ends_at' => null,
            'max_uses_per_customer' => null,
            'max_uses_total' => null,
            'uses_total' => 0,
            'allow_with_points' => true,
            'on_reduced_lines' => false,
            'for_retail' => true,
            'for_wholesale' => true,
            'for_public' => true,
            'for_company' => true,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            ...$with,
        ]);

        foreach ($rules as [$kind, $target, $targetId]) {
            DB::table('promotions.coupon_rules')->insert(['id' => strtolower((string) Str::ulid()), 'coupon_id' => $id, 'kind' => $kind, "{$target}_id" => $targetId]);
        }

        return $id;
    }

    /**
     * A gift level of the store, switched on, for everyone, no dates, unless told otherwise — written
     * straight into its table, as a coupon is here.
     *
     * @param  array<string, mixed>  $with  columns of promotions.gift_levels
     */
    public static function giftLevel(string $variantId, int $amountMinor, string $storeCode = 'sa', array $with = []): string
    {
        $id = strtolower((string) Str::ulid());
        $now = CarbonImmutable::now();

        DB::table('promotions.gift_levels')->insert([
            'id' => $id, 'store_id' => Fx::storeId($storeCode), 'amount_minor' => $amountMinor, 'currency_code' => self::currency($storeCode),
            'variant_id' => $variantId, 'for_public' => true, 'for_company' => true, 'starts_at' => null, 'ends_at' => null,
            'active' => true, 'created_at' => $now, 'updated_at' => $now, ...$with,
        ]);

        return $id;
    }

    /** A placed order's fact, as `recordOrder` writes it, placed some time ago. */
    public static function ordered(string $customerId, CarbonImmutable $placedAt, string $storeCode = 'sa'): string
    {
        $orderId = strtolower((string) Str::ulid());
        DB::table('promotions.order_facts')->insert([
            'order_id' => $orderId, 'store_id' => Fx::storeId($storeCode), 'customer_id' => $customerId,
            'placed_at' => $placedAt->utc()->format('Y-m-d H:i:s.uP'), 'ceiling_percent' => 30,
        ]);

        return $orderId;
    }

    public static function assign(string $couponId, string $customerId, string $storeCode = 'sa'): void
    {
        DB::table('promotions.coupon_assignments')->insert([
            'coupon_id' => $couponId, 'store_id' => Fx::storeId($storeCode), 'customer_id' => $customerId, 'assigned_at' => CarbonImmutable::now(),
        ]);
    }

    /** A use already made — as `useCoupon` will write it (step 2b) — counted on the coupon too. */
    public static function used(string $couponId, string $customerId, string $storeCode = 'sa', int $discountMinor = 1_00): string
    {
        $orderId = strtolower((string) Str::ulid());
        DB::table('promotions.coupon_uses')->insert([
            'coupon_id' => $couponId, 'store_id' => Fx::storeId($storeCode), 'customer_id' => $customerId, 'order_id' => $orderId,
            'order_number' => 'SA-'.random_int(100000, 999999), 'discount_minor' => $discountMinor, 'used_at' => CarbonImmutable::now(),
        ]);
        DB::table('promotions.coupons')->where('id', $couponId)->increment('uses_total');

        return $orderId;
    }

    /**
     * A priced line, as Pricing answers it: `unit` below `listUnit` is a reduced line.
     */
    public static function line(string $variantId, int $unitMinor, int $quantity = 1, ?int $listUnitMinor = null, SaleMode $mode = SaleMode::Retail, string $storeCode = 'sa'): LinePriceDto
    {
        $unit = self::money($unitMinor, $storeCode);
        $listUnit = self::money($listUnitMinor ?? $unitMinor, $storeCode);

        return new LinePriceDto(
            $variantId, $mode, $quantity, $listUnit, $unit,
            $listUnitMinor !== null && $listUnitMinor > $unitMinor ? PriceKind::Sale : PriceKind::Base,
            $listUnit->multiply($quantity, RoundingMode::Unnecessary),
            $unit->multiply($quantity, RoundingMode::Unnecessary),
            null,
        );
    }

    /**
     * A cart's prices over these lines, its subtotals added up as Pricing adds them.
     *
     * @param  list<LinePriceDto>  $lines
     */
    public static function prices(array $lines, string $storeCode = 'sa'): CartPricesDto
    {
        $gross = self::money(0, $storeCode);
        $net = self::money(0, $storeCode);

        foreach ($lines as $line) {
            $gross = $gross->add($line->listTotal);
            $net = $net->add($line->total);
        }

        return new CartPricesDto(Fx::storeId($storeCode), self::currency($storeCode), 1500, $lines, [], $gross, $net, CarbonImmutable::now(), null);
    }
}
