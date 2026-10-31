<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Promotions\Application\Command\AssignCoupon\AssignCoupon;
use Modules\Promotions\Application\Command\AssignCoupon\AssignCouponHandler;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\Command\RefreshSegment\RefreshSegment;
use Modules\Promotions\Application\Command\RefreshSegment\RefreshSegmentHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Public\Contracts\PromotionsApi;
use Modules\Promotions\Public\Events\CouponAssigned;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| What Promotions offers Ops (promotions.md §2.1, §6; ops.md §1.5 C15, §1.11 item 7, §2.3): `CouponAssigned`
| for each customer an assignment adds, through the outbox, ids only; and the store's coupon uses over a
| range for the daily report.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** @return list<array<string, mixed>> */
function promotionsOpsEvents(): array
{
    return array_values(DB::table('public.outbox_messages')->where('type', CouponAssigned::class)->orderBy('id')->pluck('payload')
        ->map(static fn (string $payload): array => (array) json_decode($payload, true, flags: JSON_THROW_ON_ERROR))->all());
}

function promotionsOpsUse(string $couponId, string $customerId, int $discount, string $at, string $storeCode = 'sa'): void
{
    DB::table('promotions.coupon_uses')->insert([
        'coupon_id' => $couponId, 'store_id' => Fx::storeId($storeCode), 'customer_id' => $customerId,
        'order_id' => strtolower((string) Str::ulid()), 'order_number' => 'SA-'.random_int(1000, 9999), 'discount_minor' => $discount, 'used_at' => $at,
    ]);
}

it('records one CouponAssigned for each assignment that adds anyone, and pages whom it added by its moment', function () {
    $sara = Fx::customer('sara@example.test');
    $omar = Fx::customer('omar@example.test');
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE, PromotionsPermissions::SEGMENT_MANAGE], ['sa']));
    $segment = app(CreateSegmentHandler::class)->handle(new CreateSegment(Fx::storeId('sa'), 'شريحة', 'Never ordered', 'DYNAMIC', 'INDIVIDUAL', 'NO_ORDERS_EVER'));
    $coupon = Pr::coupon(with: ['eligibility' => 'ASSIGNED']);

    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));
    // Assigned again with nobody new: no event, no audit entry.
    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));
    $first = promotionsOpsEvents();
    $lina = Fx::customer('lina@example.test');
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE, PromotionsPermissions::SEGMENT_MANAGE], ['sa']));
    app(RefreshSegmentHandler::class)->handle(new RefreshSegment($segment));
    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));
    $all = promotionsOpsEvents();

    $api = app(PromotionsApi::class);
    $firstAdded = $api->assignedCustomers((string) $all[0]['assignmentId'], null, 1000);
    $page = $api->assignedCustomers((string) $all[0]['assignmentId'], null, 1);
    $rest = $api->assignedCustomers((string) $all[0]['assignmentId'], $page[0], 1000);
    $expected = [$sara, $omar];
    sort($expected);

    expect(count($first))->toBe(1)
        ->and(count($all))->toBe(2)
        ->and(array_intersect_key($all[0], array_flip(['couponId', 'storeId'])))->toEqual(['couponId' => $coupon, 'storeId' => Fx::storeId('sa')])
        ->and($all[0]['assignmentId'])->not->toBe($all[1]['assignmentId'])
        ->and($firstAdded)->toBe($expected)
        ->and([...$page, ...$rest])->toBe($expected)
        ->and($api->assignedCustomers((string) $all[1]['assignmentId'], null, 1000))->toBe([$lina])
        ->and($api->assignedCustomers(strtolower((string) Str::ulid()), null, 1000))->toBe([])
        ->and(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.assigned')->count())->toBe(2)
        ->and(fn () => $api->assignedCustomers((string) $all[0]['assignmentId'], null, 0))->toThrow(InvalidPromotionAttribute::class)
        ->and(fn () => $api->assignedCustomers('nope', null, 10))->toThrow(InvalidPromotionAttribute::class);
});

it('gives a coupon\'s code for the assigned message, none once it is deleted', function () {
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE], ['sa']));
    $coupon = Pr::coupon();
    $code = (string) DB::table('promotions.coupons')->where('id', $coupon)->value('code');
    $api = app(PromotionsApi::class);

    expect($code)->not->toBe('')
        ->and($api->couponCode($coupon))->toBe($code)
        ->and($api->couponCode(strtoupper($coupon)))->toBe($code)
        ->and($api->couponCode(strtolower((string) Str::ulid())))->toBeNull()
        ->and($api->couponCode('nope'))->toBeNull();

    DB::table('promotions.coupons')->where('id', $coupon)->delete();
    expect($api->couponCode($coupon))->toBeNull();
});

it('records nothing when the assignment is refused', function () {
    Fx::customer('sara@example.test');
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE, PromotionsPermissions::SEGMENT_MANAGE], ['sa']));
    $segment = app(CreateSegmentHandler::class)->handle(new CreateSegment(Fx::storeId('sa'), 'شريحة', 'Never ordered', 'DYNAMIC', 'INDIVIDUAL', 'NO_ORDERS_EVER'));
    // A coupon for everyone is never assigned.
    $coupon = Pr::coupon();

    expect(fn () => app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment)))->toThrow(InvalidPromotionAttribute::class)
        ->and(promotionsOpsEvents())->toBe([]);
});

it('counts the store\'s coupon uses in a range and their discounts, in the store\'s currency', function () {
    $sara = Fx::customer('sara@example.test');
    $coupon = Pr::coupon();
    $egCoupon = Pr::coupon('EG-10', 'eg');
    promotionsOpsUse($coupon, $sara, 15_00, '2026-10-01 09:00:00+00');
    promotionsOpsUse($coupon, $sara, 7_50, '2026-10-01 23:59:59+00');
    // Outside the range, and another store's.
    promotionsOpsUse($coupon, $sara, 99_00, '2026-10-02 00:00:00+00');
    promotionsOpsUse($egCoupon, $sara, 5_00, '2026-10-01 10:00:00+00', 'eg');

    $api = app(PromotionsApi::class);
    $sa = StoreId::fromString(Fx::storeId('sa'));
    $day = $api->couponsUsed($sa, CarbonImmutable::parse('2026-10-01 00:00:00+00'), CarbonImmutable::parse('2026-10-02 00:00:00+00'));
    $none = $api->couponsUsed($sa, CarbonImmutable::parse('2026-10-02'), CarbonImmutable::parse('2026-10-01'));
    $empty = $api->couponsUsed($sa, CarbonImmutable::parse('2026-10-01 09:00:00+00'), CarbonImmutable::parse('2026-10-01 09:00:00+00'));

    expect([$day->uses, $day->discount->minorUnits, $day->discount->currencyCode])->toBe([2, 22_50, 'SAR'])
        ->and([$none->uses, $none->discount->minorUnits])->toBe([0, 0])
        ->and($empty->uses)->toBe(0)
        ->and(fn () => $api->couponsUsed(StoreId::fromString(strtolower((string) Str::ulid())), CarbonImmutable::now()->subDay(), CarbonImmutable::now()))
        ->toThrow(InvalidPromotionAttribute::class);
});
