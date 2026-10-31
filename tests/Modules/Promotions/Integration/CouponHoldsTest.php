<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Promotions\Domain\Exception\CouponRefused;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponReservation;
use Modules\Promotions\Domain\Model\CouponTerms;
use Modules\Promotions\Domain\Model\CouponUsage;
use Modules\Promotions\Domain\Model\CouponUse;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\ReserveCoupon;
use Modules\Promotions\Public\Dto\SettleReservation;
use Modules\Promotions\Public\Dto\UseCoupon;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/*
| A coupon held while its customer pays online (promotions.md §9.5; stage 7 step 7, with the owner's
| answers of 2026-10-29: staff may change or delete a coupon under a hold, the paid order still gets it
| (gap 7.1 (b)); a confirm is never checked again (7.4); staff see uses, never holds (7.5)). Every helper
| is named after this file's subject.
*/

/**
 * @param  list<LinePriceDto>  $lines
 */
function couponHoldsRequest(string $customerId, array $lines, string $code = 'SUMMER-15'): CouponRequest
{
    return new CouponRequest(Fx::storeId('sa'), $code, $customerId, Audience::Public, Pr::prices($lines));
}

/**
 * Quotes the coupon, then holds it for a try with what the quote said - as Pay will.
 *
 * @param  list<LinePriceDto>  $lines
 */
function couponHoldsReserve(string $customerId, array $lines, ?string $orderId = null, string $code = 'SUMMER-15'): string
{
    $orderId ??= Pr::orderId();
    $request = couponHoldsRequest($customerId, $lines, $code);
    $quote = Pr::api()->applyCoupon($request);
    Pr::api()->reserveCoupon(new ReserveCoupon($orderId, (string) $quote->couponId, $request, $quote->discount ?? Pr::money(0), false, Pr::placing()));

    return $orderId;
}

function couponHoldsConfirm(string $orderId): void
{
    Pr::api()->confirmCoupon(new SettleReservation(Fx::storeId('sa'), $orderId, 'SA-'.substr($orderId, -6), Pr::placing()));
}

function couponHoldsRelease(string $orderId): void
{
    Pr::api()->releaseCoupon(new SettleReservation(Fx::storeId('sa'), $orderId, null, Pr::placing()));
}

function couponHoldsUses(string $couponId): int
{
    return (int) DB::table('promotions.coupons')->where('id', $couponId)->value('uses_total');
}

function couponHoldsHeld(string $couponId): int
{
    return DB::table('promotions.coupon_reservations')->where('coupon_id', $couponId)->count();
}

it('holds the coupon for the try, counting toward its limit, and does nothing the second time', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon(with: ['max_uses_total' => 1]);
    $other = Fx::customer('omar@example.test');
    // The other buyer quoted before the hold: at Pay the hold is counted under the coupon's lock.
    Fx::actAsCustomer($other);
    $earlier = Pr::api()->applyCoupon(couponHoldsRequest($other, [Pr::line($a, 200_00)]));
    Fx::actAsCustomer($customer);

    $try = Pr::orderId();
    $request = couponHoldsRequest($customer, [Pr::line($a, 200_00)]);
    $quote = Pr::api()->applyCoupon($request);
    $hold = new ReserveCoupon($try, $coupon, $request, $quote->discount ?? Pr::money(0), false, Pr::placing());
    // Pay pressed twice for one try - its own quote both times.
    Pr::api()->reserveCoupon($hold);
    Pr::api()->reserveCoupon($hold);

    // The last use is held: another buyer is told it is used up; the uses are not counted yet.
    Fx::actAsCustomer($other);
    $refused = Pr::thrown(fn () => Pr::api()->reserveCoupon(new ReserveCoupon(Pr::orderId(), $coupon, couponHoldsRequest($other, [Pr::line($a, 200_00)]), $earlier->discount ?? Pr::money(0), false, Pr::placing())), CouponRefused::class);

    expect(couponHoldsHeld($coupon))->toBe(1)
        ->and(couponHoldsUses($coupon))->toBe(0)
        ->and($refused->reason)->toBe(CouponRefusal::UsedUp)
        ->and(Pr::api()->applyCoupon(couponHoldsRequest($other, [Pr::line($a, 200_00)]))->refusal)->toBe(CouponRefusal::UsedUp);
});

it('counts a hold toward the limit per customer, too', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon(with: ['max_uses_per_customer' => 1]);
    Fx::actAsCustomer($customer);
    $earlier = Pr::api()->applyCoupon(couponHoldsRequest($customer, [Pr::line($a, 200_00)]));
    couponHoldsReserve($customer, [Pr::line($a, 200_00)]);

    $refused = Pr::thrown(fn () => Pr::api()->reserveCoupon(new ReserveCoupon(Pr::orderId(), $coupon, couponHoldsRequest($customer, [Pr::line($a, 200_00)]), $earlier->discount ?? Pr::money(0), false, Pr::placing())), CouponRefused::class);

    expect($refused->reason)->toBe(CouponRefusal::UsedUpForYou)
        ->and(Pr::api()->applyCoupon(couponHoldsRequest($customer, [Pr::line($a, 200_00)]))->refusal)->toBe(CouponRefusal::UsedUpForYou);
});

it('makes the hold the order\'s use when confirmed - once - with its number', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon();
    Fx::actAsCustomer($customer);
    $try = couponHoldsReserve($customer, [Pr::line($a, 200_00)]);

    couponHoldsConfirm($try);
    couponHoldsConfirm($try);
    $use = DB::table('promotions.coupon_uses')->where('order_id', $try)->first();

    expect(couponHoldsUses($coupon))->toBe(1)
        ->and(couponHoldsHeld($coupon))->toBe(0)
        ->and((int) $use?->discount_minor)->toBe(30_00)
        ->and($use?->order_number)->toBe('SA-'.substr($try, -6));
});

it('releases a hold counting nothing, once, and frees the last use for another buyer', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon(with: ['max_uses_total' => 1]);
    Fx::actAsCustomer($customer);
    $try = couponHoldsReserve($customer, [Pr::line($a, 200_00)]);

    couponHoldsRelease($try);
    couponHoldsRelease($try);
    couponHoldsConfirm($try);

    $other = Fx::customer('omar@example.test');
    Fx::actAsCustomer($other);
    couponHoldsReserve($other, [Pr::line($a, 200_00)]);

    expect(couponHoldsUses($coupon))->toBe(0)
        ->and(couponHoldsHeld($coupon))->toBe(1)
        ->and(DB::table('promotions.coupon_uses')->where('order_id', $try)->exists())->toBeFalse();
});

it('lets staff lower the limit under a hold, and still records the paid order\'s use (gap 7.1 (b))', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon(with: ['max_uses_total' => 2]);
    Fx::actAsCustomer($customer);
    Pr::used($coupon, Fx::customer('omar@example.test'));
    $try = couponHoldsReserve($customer, [Pr::line($a, 200_00)]);
    DB::table('promotions.coupons')->where('id', $coupon)->update(['max_uses_total' => 1]);

    couponHoldsConfirm($try);

    expect(couponHoldsUses($coupon))->toBe(2);
});

it('lets staff switch the coupon off under a hold; the confirm is never checked again (gap 7.4)', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon();
    Fx::actAsCustomer($customer);
    $try = couponHoldsReserve($customer, [Pr::line($a, 200_00)]);
    DB::table('promotions.coupons')->where('id', $coupon)->update(['active' => false]);

    couponHoldsConfirm($try);

    expect(couponHoldsUses($coupon))->toBe(1);
});

it('takes the hold with a coupon deleted under it; the confirm then records nothing (gap 7.1 (b))', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon();
    Fx::actAsCustomer($customer);
    $try = couponHoldsReserve($customer, [Pr::line($a, 200_00)]);
    DB::table('promotions.coupons')->where('id', $coupon)->delete();

    couponHoldsConfirm($try);

    expect(DB::table('promotions.coupon_reservations')->count())->toBe(0)
        ->and(DB::table('promotions.coupon_uses')->where('order_id', $try)->exists())->toBeFalse();
});

it('holds once when the same try comes twice at the same moment: the second finds the first under the lock', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    $coupon = Pr::coupon();
    Fx::actAsCustomer($customer);
    $try = Pr::orderId();
    $request = couponHoldsRequest($customer, [Pr::line($a, 200_00)]);
    $quote = Pr::api()->applyCoupon($request);
    $hold = new ReserveCoupon($try, $coupon, $request, $quote->discount ?? Pr::money(0), false, Pr::placing());
    Pr::api()->reserveCoupon($hold);

    // The second call's first look, before the lock, saw no hold yet - as a call racing the first does.
    $real = app(Coupons::class);
    app()->instance(Coupons::class, new class($real) implements Coupons
    {
        private int $looks = 0;

        public function __construct(private Coupons $real) {}

        public function reservationOf(string $orderId, bool $lock = false): ?CouponReservation
        {
            return ++$this->looks === 1 ? null : $this->real->reservationOf($orderId, $lock);
        }

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
            return $this->real->useOf($orderId, $lock);
        }

        public function reserve(Coupon $coupon, string $customerId, string $orderId, int $discountMinor, DateTimeImmutable $at): void
        {
            $this->real->reserve($coupon, $customerId, $orderId, $discountMinor, $at);
        }

        public function dropReservation(string $orderId): bool
        {
            return $this->real->dropReservation($orderId);
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

    Pr::api()->reserveCoupon($hold);

    expect(couponHoldsHeld($coupon))->toBe(1);
});

it('refuses a hold for an order that already used a coupon, and a second coupon on one try', function () {
    $customer = Fx::customer();
    [$a] = Px::ready()['variants'];
    Pr::coupon();
    Pr::coupon('OTHER-10', with: ['percent' => 10]);
    Fx::actAsCustomer($customer);
    $order = Pr::orderId();
    $request = couponHoldsRequest($customer, [Pr::line($a, 200_00)]);
    $quote = Pr::api()->applyCoupon($request);
    Pr::api()->useCoupon(new UseCoupon($order, 'SA-1', (string) $quote->couponId, $request, $quote->discount ?? Pr::money(0), false, Pr::placing()));
    $try = couponHoldsReserve($customer, [Pr::line($a, 200_00)]);

    expect(fn () => couponHoldsReserve($customer, [Pr::line($a, 200_00)], $order))->toThrow(InvalidPromotionAttribute::class)
        ->and(fn () => couponHoldsReserve($customer, [Pr::line($a, 200_00)], $try, 'OTHER-10'))->toThrow(CouponRefused::class);
});
