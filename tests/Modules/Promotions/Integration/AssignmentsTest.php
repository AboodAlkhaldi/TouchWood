<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Promotions\Application\Command\AssignCoupon\AssignCoupon;
use Modules\Promotions\Application\Command\AssignCoupon\AssignCouponHandler;
use Modules\Promotions\Application\Command\ChangeCoupon\ChangeCoupon;
use Modules\Promotions\Application\Command\ChangeCoupon\ChangeCouponHandler;
use Modules\Promotions\Application\Command\CouponFields;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\Command\RefreshSegment\RefreshSegment;
use Modules\Promotions\Application\Command\RefreshSegment\RefreshSegmentHandler;
use Modules\Promotions\Application\Command\RemoveAssignment\RemoveAssignment;
use Modules\Promotions\Application\Command\RemoveAssignment\RemoveAssignmentHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\CouponInUse;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Domain\Exception\SegmentNotFound;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\CouponResult;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Assigning a coupon to a segment (promotions.md §1.4, §1.1; §8 "Assignments and segments"): the
| segment's members of that moment become the coupon's customers and stay; assigning again adds the
| new ones only; only an ASSIGNED coupon is assigned (owner, 2026-10-18, answer 24), from a segment of
| its own store and of an audience it allows; an unused assignment can be removed. And
| `segmentMembers`, keyset-paged, for Ops later.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function marketer(): void
{
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE, PromotionsPermissions::SEGMENT_MANAGE], ['sa', 'eg']));
}

function neverOrderedSegment(string $audience = 'INDIVIDUAL', string $storeCode = 'sa'): string
{
    return app(CreateSegmentHandler::class)->handle(new CreateSegment(Fx::storeId($storeCode), 'شريحة', 'Never ordered', 'DYNAMIC', $audience, 'NO_ORDERS_EVER'));
}

/**
 * @param  array<string, mixed>  $with
 */
function assignedCoupon(array $with = []): string
{
    return Pr::coupon(with: ['eligibility' => 'ASSIGNED', ...$with]);
}

/** @return list<string> */
function couponCustomers(string $couponId): array
{
    return array_values(array_map(strval(...), DB::table('promotions.coupon_assignments')->where('coupon_id', $couponId)->orderBy('customer_id')->pluck('customer_id')->all()));
}

it('writes the segment\'s members of that moment as the coupon\'s customers, audited', function () {
    $sara = Fx::customer('sara@example.test');
    $omar = Fx::customer('omar@example.test');
    marketer();
    $segment = neverOrderedSegment();
    $coupon = assignedCoupon();

    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));
    $expected = [$sara, $omar];
    sort($expected);
    $audit = DB::table('platform.audit_entries')->where('action', 'promotions.coupon.assigned')->first();

    expect(couponCustomers($coupon))->toBe($expected)
        ->and(DB::table('promotions.coupon_assignments')->where('coupon_id', $coupon)->pluck('segment_id')->unique()->values()->all())->toBe([$segment])
        ->and(json_decode((string) $audit?->changes, true))->toBe(['segment' => [null, $segment], 'customers' => [null, 2]]);
});

it('keeps a customer who leaves the segment later, and adds only the new members when assigned again', function () {
    $sara = Fx::customer('sara@example.test');
    marketer();
    $segment = neverOrderedSegment();
    $coupon = assignedCoupon();
    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));

    // Sara orders: she leaves "never ordered" — and keeps the coupon (handoff §11.2).
    Pr::ordered($sara, CarbonImmutable::now()->subDay());
    $omar = Fx::customer('omar@example.test');
    marketer();
    app(RefreshSegmentHandler::class)->handle(new RefreshSegment($segment));
    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));
    $expected = [$sara, $omar];
    sort($expected);

    expect(couponCustomers($coupon))->toBe($expected)
        ->and(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.assigned')->count())->toBe(2);

    // Nothing new: nothing written, nothing audited.
    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));
    expect(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.assigned')->count())->toBe(2);
});

it('lets an assigned customer use the coupon at once, and nobody else', function () {
    seed(CatalogSeeder::class);
    $sara = Fx::customer('sara@example.test');
    marketer();
    $segment = neverOrderedSegment();
    assignedCoupon();
    app(AssignCouponHandler::class)->handle(new AssignCoupon((string) DB::table('promotions.coupons')->value('id'), $segment));
    $outsider = Fx::customer('late@example.test');
    [$variant] = Px::ready()['variants'];
    $try = static fn (string $customer): CouponResult => Pr::api()->applyCoupon(new CouponRequest(Fx::storeId('sa'), 'SUMMER-15', $customer, Audience::Public, Pr::prices([Pr::line($variant, 100_00)])));

    expect($try($sara)->discount?->minorUnits)->toBe(15_00)
        ->and($try($outsider)->refusal)->toBe(CouponRefusal::NotAssignedToYou);
});

it('refuses what can never be assigned, writing nothing', function (Closure $make, string $field) {
    Fx::customer('sara@example.test');
    Fx::customer('firm@example.test', 'sa', 'company');
    marketer();
    [$coupon, $segment] = $make();

    expect(Pr::thrown(fn () => app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment)), InvalidPromotionAttribute::class)->attribute)->toBe($field)
        ->and(DB::table('promotions.coupon_assignments')->count())->toBe(0);
})->with([
    'a coupon anyone may use' => [fn () => [Pr::coupon(), neverOrderedSegment()], 'eligibility'],
    'another store\'s segment' => [fn () => [assignedCoupon(), neverOrderedSegment(storeCode: 'eg')], 'segment'],
    'no segment' => [fn () => [assignedCoupon(), Pr::orderId()], 'segment'],
    'individuals to a coupon for companies only' => [fn () => [assignedCoupon(['for_public' => false]), neverOrderedSegment()], 'audience'],
    'companies to a coupon for the public only' => [fn () => [assignedCoupon(['for_company' => false]), neverOrderedSegment('COMPANY')], 'audience'],
]);

it('refuses a coupon assigned from an individuals\' segment being changed to leave individuals out', function () {
    Fx::customer('sara@example.test');
    marketer();
    $segment = neverOrderedSegment();
    $coupon = assignedCoupon();
    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));
    $fields = new CouponFields('كوبون', 'Coupon', 'ASSIGNED', 'PERCENT', 15, null, null, null, CarbonImmutable::now(), null, null, null, true, false, true, true, false, true, []);

    expect(Pr::thrown(fn () => app(ChangeCouponHandler::class)->handle(new ChangeCoupon($coupon, $fields)), InvalidPromotionAttribute::class)->attribute)->toBe('buyers');
});

it('takes back an unused assignment, audited; never a used one; nothing for one never given', function () {
    $sara = Fx::customer('sara@example.test');
    $omar = Fx::customer('omar@example.test');
    $late = Fx::customer('late@example.test');
    marketer();
    $segment = neverOrderedSegment();
    $coupon = assignedCoupon();
    app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment));
    Pr::used($coupon, $omar);
    DB::table('promotions.coupon_assignments')->where('coupon_id', $coupon)->where('customer_id', $late)->delete();

    app(RemoveAssignmentHandler::class)->handle(new RemoveAssignment($coupon, strtoupper($sara)));

    expect(couponCustomers($coupon))->not->toContain($sara)
        ->and(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.assignment_removed')->count())->toBe(1)
        ->and(fn () => app(RemoveAssignmentHandler::class)->handle(new RemoveAssignment($coupon, $omar)))->toThrow(CouponInUse::class)
        ->and(couponCustomers($coupon))->toContain($omar);

    app(RemoveAssignmentHandler::class)->handle(new RemoveAssignment($coupon, $late));
    expect(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.assignment_removed')->count())->toBe(1);
});

it('lets only someone managing coupons assign', function () {
    Fx::customer('sara@example.test');
    marketer();
    $segment = neverOrderedSegment();
    $coupon = assignedCoupon();
    Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::SEGMENT_MANAGE], ['sa']));

    expect(fn () => app(AssignCouponHandler::class)->handle(new AssignCoupon($coupon, $segment)))->toThrow(Unauthorized::class)
        ->and(fn () => app(RemoveAssignmentHandler::class)->handle(new RemoveAssignment($coupon, Pr::orderId())))->toThrow(Unauthorized::class);
});

it('pages a segment\'s members by id for Ops, refusing an unknown segment and a page out of bounds', function () {
    $ids = [];

    foreach (range(1, 5) as $n) {
        $ids[] = Fx::customer("member{$n}@example.test");
    }

    sort($ids);
    marketer();
    $segment = neverOrderedSegment();
    $first = Pr::api()->segmentMembers($segment, null, 2);
    $second = Pr::api()->segmentMembers($segment, $first[1], 2);
    $last = Pr::api()->segmentMembers($segment, $second[1], 2);

    expect([...$first, ...$second, ...$last])->toBe($ids)
        ->and(Pr::api()->segmentMembers($segment, $last[0], 2))->toBe([])
        ->and(fn () => Pr::api()->segmentMembers(Pr::orderId(), null, 2))->toThrow(SegmentNotFound::class)
        ->and(Pr::thrown(fn () => Pr::api()->segmentMembers($segment, null, 0), InvalidPromotionAttribute::class)->attribute)->toBe('limit')
        ->and(Pr::thrown(fn () => Pr::api()->segmentMembers($segment, null, 1001), InvalidPromotionAttribute::class)->attribute)->toBe('limit');
});
