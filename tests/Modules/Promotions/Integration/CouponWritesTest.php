<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Authorization\InvalidPermissionCheck;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Promotions\Application\Command\UseCoupon\UseCouponHandler;
use Modules\Promotions\Domain\Exception\CouponChanged;
use Modules\Promotions\Domain\Exception\CouponRefused;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponTerms;
use Modules\Promotions\Domain\Model\CouponUsage;
use Modules\Promotions\Domain\Model\CouponUse;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Dto\CallerPermission;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\CouponResult;
use Modules\Promotions\Public\Dto\EditedCouponRequest;
use Modules\Promotions\Public\Dto\RecordOrder;
use Modules\Promotions\Public\Dto\UpdateCouponUse;
use Modules\Promotions\Public\Dto\UseCoupon;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Promotions' writes as Sales makes them (promotions.md §1.3, §1.6, §2.1; the scenarios of §8 "Coupon
| uses"): using the quoted coupon as the order is placed — every check again, refused or changed, never
| the old discount — recording the order's fact with the ceiling of the moment, and a staff edit: the
| coupon worked out again on the edited lines, never above its discount now, and the use's new
| discount recorded once. Sales does not exist yet: its permissions are a stand-in (fixtures).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  list<LinePriceDto>  $lines
 */
function couponWritesRequest(string $customerId, array $lines, string $code = 'SUMMER-15', Audience $audience = Audience::Public, string $storeCode = 'sa'): CouponRequest
{
    return new CouponRequest(Fx::storeId($storeCode), $code, $customerId, $audience, Pr::prices($lines, $storeCode));
}

/**
 * Quotes the coupon, then places the order with what the quote said — as checkout will.
 *
 * @param  list<LinePriceDto>  $lines
 */
function couponWritesPlace(string $customerId, array $lines, string $code = 'SUMMER-15', ?string $orderId = null, ?Money $quoted = null, bool $usesPoints = false, ?CallerPermission $caller = null, ?CouponResult $quote = null): string
{
    $orderId ??= Pr::orderId();
    $request = couponWritesRequest($customerId, $lines, $code);
    $quote ??= Pr::api()->applyCoupon($request);

    Pr::api()->useCoupon(new UseCoupon(
        $orderId, 'SA-'.substr($orderId, -6), (string) $quote->couponId, $request,
        $quoted ?? $quote->discount ?? Pr::money(0), $usesPoints, $caller ?? Pr::placing(),
    ));

    return $orderId;
}

function couponWritesUses(string $couponId): int
{
    return (int) DB::table('promotions.coupons')->where('id', $couponId)->value('uses_total');
}

/**
 * @param  list<LinePriceDto>  $lines
 */
function couponWritesAfterEdit(string $orderId, string $couponId, array $lines, int $roomMinor, int $nowMinor): CouponResult
{
    return Pr::api()->couponAfterEdit(new EditedCouponRequest($orderId, $couponId, Pr::prices($lines), Audience::Public, Pr::money($roomMinor), Pr::money($nowMinor)));
}

describe('using the coupon as the order is placed', function () {
    it('records the use with the discount quoted, the order\'s number, and counts it on the coupon', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);

        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);
        $use = DB::table('promotions.coupon_uses')->where('order_id', $order)->first();

        expect($use?->coupon_id)->toBe($coupon)
            ->and($use?->customer_id)->toBe($customer)
            ->and($use?->store_id)->toBe(Fx::storeId('sa'))
            ->and((int) $use?->discount_minor)->toBe(30_00)
            ->and($use?->order_number)->toBe('SA-'.substr($order, -6))
            ->and($use?->last_edit_id)->toBeNull()
            ->and(couponWritesUses($coupon))->toBe(1);
    });

    it('does nothing the second time for the same order and coupon, and refuses another coupon on it', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Pr::coupon('OTHER-10', with: ['percent' => 10]);
        Fx::actAsCustomer($customer);

        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);
        couponWritesPlace($customer, [Pr::line($a, 200_00)], orderId: $order);

        expect(couponWritesUses($coupon))->toBe(1)
            ->and(DB::table('promotions.coupon_uses')->count())->toBe(1)
            ->and(Pr::thrown(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00)], 'OTHER-10', $order), CouponRefused::class)->context())
            ->toBe(['reason' => 'ANOTHER_COUPON']);
    });

    it('asks every check again: no longer usable is refused with its reason, never the old discount', function (Closure $since, CouponRefusal $reason, bool $usesPoints) {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon(with: ['eligibility' => 'ASSIGNED']);
        Pr::assign($coupon, $customer);
        Fx::actAsCustomer($customer);
        $quote = Pr::api()->applyCoupon(couponWritesRequest($customer, [Pr::line($a, 200_00)]));

        $since($coupon, $customer);
        $usesBefore = couponWritesUses($coupon);

        expect(Pr::thrown(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00)], usesPoints: $usesPoints, quote: $quote), CouponRefused::class)->reason)
            ->toBe($reason)
            ->and(DB::table('promotions.coupon_uses')->count())->toBe(0)
            ->and(couponWritesUses($coupon))->toBe($usesBefore);
    })->with([
        'the assignment removed' => [fn (string $coupon) => DB::table('promotions.coupon_assignments')->where('coupon_id', $coupon)->delete(), CouponRefusal::NotAssignedToYou, false],
        'switched off' => [fn (string $coupon) => DB::table('promotions.coupons')->where('id', $coupon)->update(['active' => false]), CouponRefusal::Inactive, false],
        'ended' => [fn (string $coupon) => DB::table('promotions.coupons')->where('id', $coupon)->update(['starts_at' => now()->subDays(2), 'ends_at' => now()->subSecond()]), CouponRefusal::Ended, false],
        'used up meanwhile' => [fn (string $coupon) => DB::table('promotions.coupons')->where('id', $coupon)->update(['max_uses_total' => 1, 'uses_total' => 1]), CouponRefusal::UsedUp, false],
        'the ceiling lowered' => [fn () => Pr::ceiling(10), CouponRefusal::PassesCeiling, false],
        'points no longer allowed' => [fn (string $coupon) => DB::table('promotions.coupons')->where('id', $coupon)->update(['allow_with_points' => false]), CouponRefusal::NotWithPoints, true],
    ]);

    it('lets an order without points through a coupon that does not allow them', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon(with: ['allow_with_points' => false]);
        Fx::actAsCustomer($customer);

        couponWritesPlace($customer, [Pr::line($a, 200_00)]);

        expect(couponWritesUses($coupon))->toBe(1);
    });

    it('refuses as changed a coupon still usable that now takes off something else: checkout quotes again', function (Closure $since) {
        $customer = Fx::customer();
        // Two products, so a rule can exclude one and leave the other covered.
        [$a] = Px::ready()['variants'];
        [$b] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);
        $quote = Pr::api()->applyCoupon(couponWritesRequest($customer, [Pr::line($a, 200_00), Pr::line($b, 100_00)]));

        $since($coupon, $b);

        expect(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00), Pr::line($b, 100_00)], quote: $quote))->toThrow(CouponChanged::class)
            ->and(couponWritesUses($coupon))->toBe(0);
    })->with([
        'the percentage changed' => [fn (string $coupon) => DB::table('promotions.coupons')->where('id', $coupon)->update(['percent' => 10])],
        'the rules changed' => [fn (string $coupon, string $variant) => DB::table('promotions.coupon_rules')->insert(['id' => Pr::orderId(), 'coupon_id' => $coupon, 'kind' => 'EXCLUDE', 'product_id' => DB::table('catalog.variants')->where('id', $variant)->value('product_id')])],
    ]);

    it('refuses a quoted discount that is not what the coupon takes off now', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon();
        Fx::actAsCustomer($customer);

        expect(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00)], quoted: Pr::money(29_99)))->toThrow(CouponChanged::class)
            ->and(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00)], quoted: Pr::money(0)))->toThrow(InvalidPromotionAttribute::class)
            ->and(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00)], quoted: Pr::money(30_00, 'eg')))->toThrow(InvalidPromotionAttribute::class);
    });

    it('refuses a coupon deleted since the quote as unknown', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);
        $quote = Pr::api()->applyCoupon(couponWritesRequest($customer, [Pr::line($a, 200_00)]));
        DB::table('promotions.coupons')->where('id', $coupon)->delete();

        expect(Pr::thrown(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00)], quote: $quote), CouponRefused::class)->reason)
            ->toBe(CouponRefusal::UnknownCode);
    });

    it('checks the caller\'s permission in the coupon\'s store, and refuses another store passed in', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);
        $quote = Pr::api()->applyCoupon(couponWritesRequest($customer, [Pr::line($a, 200_00)]));
        $egRequest = couponWritesRequest($customer, [Pr::line($a, 200_00, storeCode: 'eg')], storeCode: 'eg');
        $use = static fn (CouponRequest $request, CallerPermission $caller) => Pr::api()->useCoupon(new UseCoupon(Pr::orderId(), 'SA-1', $coupon, $request, Pr::money(30_00), false, $caller));
        $sa = couponWritesRequest($customer, [Pr::line($a, 200_00)]);

        expect(Pr::thrown(fn () => $use($egRequest, Pr::placing()), InvalidPromotionAttribute::class)->attribute)->toBe('store')
            ->and(Pr::thrown(fn () => $use($sa, new CallerPermission('sales', 'loyalty.points.view')), InvalidPromotionAttribute::class)->attribute)->toBe('permission')
            ->and(fn () => $use($sa, new CallerPermission('promotions', 'promotions.coupon.manage')))->toThrow(InvalidPromotionAttribute::class)
            // A store-free permission cannot be asked in a store: Access's own refusal.
            ->and(fn () => $use($sa, new CallerPermission('sales', Pr::SYSTEM)))->toThrow(InvalidPermissionCheck::class);

        // A staff member holds no customer's placing.
        Fx::actAsStaff(Fx::staffWith([Pr::EDIT], ['sa']));
        expect(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00)], quote: $quote))->toThrow(Unauthorized::class)
            ->and(couponWritesUses($coupon))->toBe(0);
    });

    it('refuses the same order said to be another customer\'s', function () {
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        [$a] = Px::ready()['variants'];
        Pr::coupon();
        Fx::actAsCustomer($sara);
        $order = couponWritesPlace($sara, [Pr::line($a, 200_00)]);
        Fx::actAsCustomer($omar);

        expect(Pr::thrown(fn () => couponWritesPlace($omar, [Pr::line($a, 200_00)], orderId: $order), InvalidPromotionAttribute::class)->attribute)->toBe('customer');
    });
});

describe('using the coupon: replays and races', function () {
    it('refuses the placement replayed with another discount than the one recorded, and takes it after an edit', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon();
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);

        expect(Pr::thrown(fn () => couponWritesPlace($customer, [Pr::line($a, 200_00)], orderId: $order, quoted: Pr::money(29_00)), InvalidPromotionAttribute::class)->attribute)->toBe('amount');

        // Lowered by an edit since: the placement's own figure is no longer the recorded one.
        Fx::actAsStaff(Fx::staffWith([Pr::EDIT], ['sa']));
        Pr::api()->updateCouponUse(new UpdateCouponUse($order, Pr::orderId(), Pr::money(20_00), Pr::editing()));
        Fx::actAsCustomer($customer);
        couponWritesPlace($customer, [Pr::line($a, 200_00)], orderId: $order);

        expect((int) DB::table('promotions.coupon_uses')->where('order_id', $order)->value('discount_minor'))->toBe(20_00);
    });

    it('refuses a customer who does not exist before anything is written', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);
        $quote = Pr::api()->applyCoupon(couponWritesRequest($customer, [Pr::line($a, 200_00)]));
        $nobody = Pr::orderId();

        expect(Pr::thrown(fn () => Pr::api()->useCoupon(new UseCoupon(Pr::orderId(), 'SA-1', $coupon, couponWritesRequest($nobody, [Pr::line($a, 200_00)]), $quote->discount ?? Pr::money(0), false, Pr::placing())), InvalidPromotionAttribute::class)->attribute)->toBe('customer')
            ->and(couponWritesUses($coupon))->toBe(0);
    });

    it('answers two coupons on one order at once with "another coupon", the order\'s unique key the backstop', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon();
        $other = Pr::coupon('OTHER-10', with: ['percent' => 10]);
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);
        // The second placement read the order before the first wrote it: its two reads find nothing.
        $real = app(Coupons::class);
        app()->instance(Coupons::class, new class($real) implements Coupons
        {
            private int $reads = 0;

            public function __construct(private readonly Coupons $real) {}

            public function byCode(StoreId $store, string $code): ?Coupon
            {
                return $this->real->byCode($store, $code);
            }

            public function byId(StoreId $store, string $couponId): ?Coupon
            {
                return $this->real->byId($store, $couponId);
            }

            public function lock(StoreId $store, string $couponId): ?Coupon
            {
                return $this->real->lock($store, $couponId);
            }

            public function storeOf(string $couponId): ?StoreId
            {
                return $this->real->storeOf($couponId);
            }

            public function usage(StoreId $store, string $couponId, string $customerId): CouponUsage
            {
                return $this->real->usage($store, $couponId, $customerId);
            }

            public function useOf(string $orderId, bool $lock = false): ?CouponUse
            {
                // Its read before the lock and its read under it saw no use; what comes after is real.
                return ++$this->reads <= 2 ? null : $this->real->useOf($orderId, $lock);
            }

            public function recordUse(Coupon $coupon, string $customerId, string $orderId, string $orderNumber, int $discountMinor, DateTimeImmutable $at): void
            {
                $this->real->recordUse($coupon, $customerId, $orderId, $orderNumber, $discountMinor, $at);
            }

            public function lowerUse(string $orderId, string $editId, int $discountMinor): void
            {
                $this->real->lowerUse($orderId, $editId, $discountMinor);
            }

            public function codeTaken(StoreId $store, string $code): bool
            {
                return $this->real->codeTaken($store, $code);
            }

            public function create(string $couponId, StoreId $store, string $code, CouponTerms $terms, bool $active, DateTimeImmutable $at): void
            {
                $this->real->create($couponId, $store, $code, $terms, $active, $at);
            }

            public function change(Coupon $coupon, CouponTerms $terms, DateTimeImmutable $at): void
            {
                $this->real->change($coupon, $terms, $at);
            }

            public function switchTo(Coupon $coupon, bool $active, DateTimeImmutable $at): void
            {
                $this->real->switchTo($coupon, $active, $at);
            }

            public function wasUsed(Coupon $coupon): bool
            {
                return $this->real->wasUsed($coupon);
            }

            public function delete(Coupon $coupon): void
            {
                $this->real->delete($coupon);
            }
        });
        app()->forgetInstance(UseCouponHandler::class);
        app()->forgetInstance(PromotionsApi::class);

        $request = couponWritesRequest($customer, [Pr::line($a, 200_00)], 'OTHER-10');

        expect(Pr::thrown(fn () => app(PromotionsApi::class)->useCoupon(new UseCoupon($order, 'SA-1', $other, $request, Pr::money(20_00), false, Pr::placing())), CouponRefused::class)->context())->toBe(['reason' => 'ANOTHER_COUPON'])
            ->and(couponWritesUses($other))->toBe(0)
            ->and(DB::table('promotions.coupon_uses')->count())->toBe(1);
    });
});

describe('recording the order', function () {
    it('records the order with the store\'s ceiling of the moment, once', function () {
        $customer = Fx::customer();
        Pr::ceiling(20);
        Fx::actAsCustomer($customer);
        $order = Pr::orderId();
        $placed = CarbonImmutable::parse('2026-10-17 10:00:00');

        Pr::api()->recordOrder(new RecordOrder($order, $customer, Fx::storeId('sa'), $placed, Pr::placing()));
        Pr::api()->recordOrder(new RecordOrder($order, $customer, Fx::storeId('sa'), $placed->addHour(), Pr::placing()));
        $fact = DB::table('promotions.order_facts')->where('order_id', $order)->first();

        expect(DB::table('promotions.order_facts')->count())->toBe(1)
            ->and((int) $fact?->ceiling_percent)->toBe(20)
            ->and($fact?->customer_id)->toBe($customer)
            ->and(CarbonImmutable::parse((string) $fact?->placed_at)->equalTo($placed))->toBeTrue();
    });

    it('refuses the same order said to be another customer\'s or store\'s, and a customer who does not exist', function () {
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        Fx::actAsCustomer($sara);
        $order = Pr::orderId();
        Pr::api()->recordOrder(new RecordOrder($order, $sara, Fx::storeId('sa'), now()->toImmutable(), Pr::placing()));

        expect(Pr::thrown(fn () => Pr::api()->recordOrder(new RecordOrder($order, $omar, Fx::storeId('sa'), now()->toImmutable(), Pr::placing())), InvalidPromotionAttribute::class)->attribute)->toBe('customer')
            ->and(Pr::thrown(fn () => Pr::api()->recordOrder(new RecordOrder($order, $sara, Fx::storeId('eg'), now()->toImmutable(), Pr::placing())), InvalidPromotionAttribute::class)->attribute)->toBe('store')
            ->and(Pr::thrown(fn () => Pr::api()->recordOrder(new RecordOrder(Pr::orderId(), Pr::orderId(), Fx::storeId('sa'), now()->toImmutable(), Pr::placing())), InvalidPromotionAttribute::class)->attribute)->toBe('customer');
    });

    it('checks the caller\'s permission in the order\'s store', function () {
        $customer = Fx::customer();
        Fx::actAsStaff(Fx::staffWith([Pr::EDIT], ['sa']));

        expect(fn () => Pr::api()->recordOrder(new RecordOrder(Pr::orderId(), $customer, Fx::storeId('sa'), now()->toImmutable(), Pr::placing())))->toThrow(Unauthorized::class)
            ->and(fn () => Pr::api()->recordOrder(new RecordOrder(Pr::orderId(), $customer, Fx::storeId('eg'), now()->toImmutable(), Pr::editing())))->toThrow(Unauthorized::class)
            ->and(DB::table('promotions.order_facts')->count())->toBe(0);

        $order = Pr::orderId();
        Pr::api()->recordOrder(new RecordOrder($order, $customer, Fx::storeId('sa'), now()->toImmutable(), Pr::editing()));
        expect(DB::table('promotions.order_facts')->count())->toBe(1);

        // The order's recorded store answers first: another store passed in is refused as such, even
        // by a caller with no permission there.
        expect(Pr::thrown(fn () => Pr::api()->recordOrder(new RecordOrder($order, $customer, Fx::storeId('eg'), now()->toImmutable(), Pr::editing())), InvalidPromotionAttribute::class)->attribute)->toBe('store');
    });

    it('works an edited order\'s room by the ceiling it was placed under, though the store\'s changed since', function () {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);
        $order = Pr::orderId();
        Pr::api()->recordOrder(new RecordOrder($order, $customer, Fx::storeId('sa'), now()->toImmutable(), Pr::placing()));
        Pr::ceiling(10);

        expect(Pr::api()->orderDiscountRoom($order, Pr::money(1_000_00), Pr::money(900_00))->minorUnits)->toBe(200_00)
            ->and(Pr::api()->customerDiscountRoom(StoreId::fromString(Fx::storeId('sa')), Pr::money(1_000_00), Pr::money(900_00))->minorUnits)->toBe(0)
            ->and(fn () => Pr::api()->orderDiscountRoom(Pr::orderId(), Pr::money(1_000_00), Pr::money(900_00)))->toThrow(InvalidPromotionAttribute::class)
            ->and(Pr::thrown(fn () => Pr::api()->orderDiscountRoom($order, Pr::money(1_000_00, 'eg'), Pr::money(900_00, 'eg')), InvalidPromotionAttribute::class)->attribute)->toBe('currency')
            ->and(Pr::thrown(fn () => Pr::api()->orderDiscountRoom($order, Pr::money(1_000_00), Pr::money(900_00, 'eg')), InvalidPromotionAttribute::class)->attribute)->toBe('currency');
    });
});

describe('after a staff edit', function () {
    it('works the coupon out on the edited lines, never above its discount now', function () {
        $customer = Fx::customer();
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);

        // A line taken away: 15% of 100 = 15, below the 30 now.
        $smaller = couponWritesAfterEdit($order, $coupon, [Pr::line($a, 100_00)], 1_000_00, 30_00);
        // A line added: 15% of 400 = 60, held at the 30 it is now, spread over both lines.
        $larger = couponWritesAfterEdit($order, $coupon, [Pr::line($a, 200_00), Pr::line($b, 200_00)], 1_000_00, 30_00);

        expect($smaller->discount?->minorUnits)->toBe(15_00)
            ->and($larger->discount?->minorUnits)->toBe(30_00)
            ->and(array_map(static fn (Money $share): int => $share->minorUnits, $larger->shares))->toBe([15_00, 15_00]);
    });

    it('stays at an earlier edit\'s trimmed amount or below, the recorded use the truth', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);
        Fx::actAsStaff(Fx::staffWith([Pr::EDIT], ['sa']));
        Pr::api()->updateCouponUse(new UpdateCouponUse($order, Pr::orderId(), Pr::money(20_00), Pr::editing()));

        // The caller's figure may only lower it: a stale 30 is held to the recorded 20.
        expect(couponWritesAfterEdit($order, $coupon, [Pr::line($a, 200_00)], 1_000_00, 30_00)->discount?->minorUnits)->toBe(20_00)
            ->and(couponWritesAfterEdit($order, $coupon, [Pr::line($a, 200_00)], 1_000_00, 10_00)->discount?->minorUnits)->toBe(10_00);
    });

    it('trims to the room only when the coupon alone passes it, and removes it when no room is left', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);

        // Trimmed shares follow the lines' own: 25 over lines of 150 and 50 is 18.75 and 6.25 — 19 and 6.
        $uneven = couponWritesAfterEdit($order, $coupon, [Pr::line($a, 150_00), Pr::line($a, 50_00)], 25_00, 30_00);

        expect(array_map(static fn (Money $share): int => $share->minorUnits, $uneven->shares))->toBe([18_75, 6_25])
            ->and($uneven->discount?->minorUnits)->toBe(25_00)
            ->and(couponWritesAfterEdit($order, $coupon, [Pr::line($a, 200_00)], 25_00, 30_00)->discount?->minorUnits)->toBe(25_00)
            ->and(couponWritesAfterEdit($order, $coupon, [Pr::line($a, 200_00)], 30_00, 30_00)->discount?->minorUnits)->toBe(30_00)
            ->and(couponWritesAfterEdit($order, $coupon, [Pr::line($a, 200_00)], 0, 30_00)->refusal)->toBe(CouponRefusal::PassesCeiling);
    });

    it('removes the coupon when the edited order is under its minimum or nothing is eligible', function () {
        $customer = Fx::customer();
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        $coupon = Pr::coupon(with: ['minimum_minor' => 150_00, 'on_reduced_lines' => false]);
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);

        expect(couponWritesAfterEdit($order, $coupon, [Pr::line($a, 149_99)], 1_000_00, 30_00)->refusal)->toBe(CouponRefusal::BelowMinimum)
            ->and(couponWritesAfterEdit($order, $coupon, [Pr::line($b, 200_00, listUnitMinor: 250_00)], 1_000_00, 30_00)->refusal)->toBe(CouponRefusal::NothingEligible);
    });

    it('does not ask the coupon\'s dates, switch, uses or assignment again: settled at placement', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon(with: ['eligibility' => 'ASSIGNED', 'max_uses_total' => 1]);
        Pr::assign($coupon, $customer);
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);
        DB::table('promotions.coupon_assignments')->where('coupon_id', $coupon)->delete();
        DB::table('promotions.coupons')->where('id', $coupon)->update(['active' => false, 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()]);

        expect(couponWritesAfterEdit($order, $coupon, [Pr::line($a, 100_00)], 1_000_00, 30_00)->discount?->minorUnits)->toBe(15_00);
    });

    it('refuses an order with no coupon, or another coupon than the order\'s', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        $other = Pr::coupon('OTHER-10');
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);

        expect(Pr::thrown(fn () => couponWritesAfterEdit($order, $other, [Pr::line($a, 100_00)], 1_000_00, 30_00), InvalidPromotionAttribute::class)->attribute)->toBe('coupon')
            ->and(Pr::thrown(fn () => couponWritesAfterEdit(Pr::orderId(), $coupon, [Pr::line($a, 100_00)], 1_000_00, 30_00), InvalidPromotionAttribute::class)->attribute)->toBe('order');
    });

    it('records the use\'s new discount once per edit, never higher, to 0 with the use still counted', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon();
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);
        Fx::actAsStaff(Fx::staffWith([Pr::EDIT], ['sa']));
        $edit = Pr::orderId();
        $discount = static fn (): int => (int) DB::table('promotions.coupon_uses')->where('order_id', $order)->value('discount_minor');

        Pr::api()->updateCouponUse(new UpdateCouponUse($order, $edit, Pr::money(20_00), Pr::editing()));
        Pr::api()->updateCouponUse(new UpdateCouponUse($order, $edit, Pr::money(20_00), Pr::editing()));

        expect($discount())->toBe(20_00)
            ->and(DB::table('promotions.coupon_uses')->where('order_id', $order)->value('last_edit_id'))->toBe($edit)
            ->and(Pr::thrown(fn () => Pr::api()->updateCouponUse(new UpdateCouponUse($order, $edit, Pr::money(10_00), Pr::editing())), InvalidPromotionAttribute::class)->attribute)->toBe('edit')
            ->and(Pr::thrown(fn () => Pr::api()->updateCouponUse(new UpdateCouponUse($order, Pr::orderId(), Pr::money(20_01), Pr::editing())), InvalidPromotionAttribute::class)->attribute)->toBe('amount')
            ->and(fn () => Pr::api()->updateCouponUse(new UpdateCouponUse($order, Pr::orderId(), Pr::money(-1), Pr::editing())))->toThrow(InvalidPromotionAttribute::class)
            ->and(Pr::thrown(fn () => Pr::api()->updateCouponUse(new UpdateCouponUse($order, Pr::orderId(), Pr::money(5_00, 'eg'), Pr::editing())), InvalidPromotionAttribute::class)->attribute)->toBe('currency');

        // Only the latest edit is held (§2.1): the first, replayed after a later one, would raise it.
        $later = Pr::orderId();
        Pr::api()->updateCouponUse(new UpdateCouponUse($order, $later, Pr::money(15_00), Pr::editing()));
        expect(Pr::thrown(fn () => Pr::api()->updateCouponUse(new UpdateCouponUse($order, $edit, Pr::money(20_00), Pr::editing())), InvalidPromotionAttribute::class)->attribute)->toBe('amount');

        Pr::api()->updateCouponUse(new UpdateCouponUse($order, Pr::orderId(), Pr::money(0), Pr::editing()));

        expect($discount())->toBe(0)
            ->and(couponWritesUses($coupon))->toBe(1)
            ->and(DB::table('promotions.coupon_uses')->count())->toBe(1);
    });

    it('checks the caller\'s permission in the store of the coupon the order used', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon();
        Fx::actAsCustomer($customer);
        $order = couponWritesPlace($customer, [Pr::line($a, 200_00)]);

        Fx::actAsStaff(Fx::staffWith([Pr::EDIT], ['eg']));
        expect(fn () => Pr::api()->updateCouponUse(new UpdateCouponUse($order, Pr::orderId(), Pr::money(10_00), Pr::editing())))->toThrow(Unauthorized::class)
            ->and(Pr::thrown(fn () => Pr::api()->updateCouponUse(new UpdateCouponUse(Pr::orderId(), Pr::orderId(), Pr::money(10_00), Pr::editing())), InvalidPromotionAttribute::class)->attribute)->toBe('order')
            ->and((int) DB::table('promotions.coupon_uses')->where('order_id', $order)->value('discount_minor'))->toBe(30_00);
    });
});
