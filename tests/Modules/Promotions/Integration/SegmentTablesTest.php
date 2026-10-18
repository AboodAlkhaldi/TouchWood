<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the segments' code (promotions.md §5; handoff §5.3): every named
| CHECK and key of the segment tables, each row breaking that one rule only (lesson 112), a CHECK on a
| nullable column broken with it left NULL (lessons 35, 162).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  array<string, mixed>  $with
 */
function segmentRow(array $with = [], string $storeCode = 'sa'): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('promotions.segments')->insert([
        'id' => $id, 'store_id' => Fx::storeId($storeCode), 'name_ar' => 'شريحة', 'name_en' => 'Segment',
        'type' => 'DYNAMIC', 'audience' => 'INDIVIDUAL', 'rule' => 'NO_ORDER_FOR', 'rule_months' => 6, 'rule_orders' => null,
        'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(), ...$with,
    ]);

    return $id;
}

it('takes every valid shape of segment, and a member', function () {
    $customer = Fx::customer();
    $static = segmentRow(['type' => 'STATIC', 'rule' => null, 'rule_months' => null]);
    segmentRow(['rule' => 'NO_ORDERS_EVER', 'rule_months' => null]);
    segmentRow(['rule' => 'AT_LEAST_ORDERS', 'rule_months' => 12, 'rule_orders' => 5, 'audience' => 'COMPANY']);
    DB::table('promotions.segment_members')->insert(['segment_id' => $static, 'customer_id' => $customer, 'added_at' => CarbonImmutable::now()]);

    expect(DB::table('promotions.segments')->count())->toBe(3)
        ->and(DB::table('promotions.segment_members')->count())->toBe(1);
});

it('refuses what the code would never write', function (Closure $write, string $constraint) {
    $customer = Fx::customer();
    $segment = segmentRow();

    expect(fn () => DB::transaction(function () use ($write, $customer, $segment): bool {
        $write($customer, $segment);

        return true;
    }))->toThrow(QueryException::class, "\"{$constraint}\"");
})->with([
    'an unknown type' => [fn () => segmentRow(['type' => 'SMART', 'rule' => null, 'rule_months' => null]), 'segments_type'],
    'an unknown audience' => [fn () => segmentRow(['audience' => 'EVERYONE']), 'segments_audience'],
    'an unknown rule' => [fn () => segmentRow(['rule' => 'BIG_SPENDERS']), 'segments_rule'],
    'a hand-picked one with a rule' => [fn () => segmentRow(['type' => 'STATIC']), 'segments_type_rule'],
    'a hand-picked one with months' => [fn () => segmentRow(['type' => 'STATIC', 'rule' => null]), 'segments_type_rule'],
    'a rule-based one with no rule' => [fn () => segmentRow(['rule' => null, 'rule_months' => null]), 'segments_type_rule'],
    'never ordered with months' => [fn () => segmentRow(['rule' => 'NO_ORDERS_EVER', 'rule_months' => 6]), 'segments_no_orders_ever'],
    'no order for with no months' => [fn () => segmentRow(['rule_months' => null]), 'segments_no_order_for'],
    'no order for 121 months' => [fn () => segmentRow(['rule_months' => 121]), 'segments_no_order_for'],
    'no order for with orders' => [fn () => segmentRow(['rule_orders' => 2]), 'segments_no_order_for'],
    'at least with no orders' => [fn () => segmentRow(['rule' => 'AT_LEAST_ORDERS', 'rule_months' => 6]), 'segments_at_least_orders'],
    'at least 1001 orders' => [fn () => segmentRow(['rule' => 'AT_LEAST_ORDERS', 'rule_months' => 6, 'rule_orders' => 1001]), 'segments_at_least_orders'],
    'at least with no months' => [fn () => segmentRow(['rule' => 'AT_LEAST_ORDERS', 'rule_months' => null, 'rule_orders' => 5]), 'segments_at_least_orders'],
    'a segment in no store' => [fn () => DB::table('promotions.segments')->where('id', '>', '')->limit(1)->update(['store_id' => strtolower((string) Str::ulid())]), 'segments_store'],
    'a member twice' => [function (string $customer, string $segment) {
        DB::table('promotions.segment_members')->insert(['segment_id' => $segment, 'customer_id' => $customer, 'added_at' => CarbonImmutable::now()]);
        DB::table('promotions.segment_members')->insert(['segment_id' => $segment, 'customer_id' => $customer, 'added_at' => CarbonImmutable::now()]);
    }, 'segment_members_pkey'],
    'a member of no segment' => [fn (string $customer) => DB::table('promotions.segment_members')->insert(['segment_id' => strtolower((string) Str::ulid()), 'customer_id' => $customer, 'added_at' => CarbonImmutable::now()]), 'segment_members_segment'],
    'a member who is nobody' => [fn (string $customer, string $segment) => DB::table('promotions.segment_members')->insert(['segment_id' => $segment, 'customer_id' => strtolower((string) Str::ulid()), 'added_at' => CarbonImmutable::now()]), 'segment_members_customer'],
    'an assignment from another store\'s segment' => [function (string $customer) {
        $egypt = segmentRow([], 'eg');
        $coupon = Pr::coupon(with: ['eligibility' => 'ASSIGNED']);
        DB::table('promotions.coupon_assignments')->insert(['coupon_id' => $coupon, 'store_id' => Fx::storeId('sa'), 'customer_id' => $customer, 'segment_id' => $egypt, 'assigned_at' => CarbonImmutable::now()]);
    }, 'coupon_assignments_segment'],
]);

it('deletes a segment\'s members with it', function () {
    $customer = Fx::customer();
    $segment = segmentRow(['type' => 'STATIC', 'rule' => null, 'rule_months' => null]);
    DB::table('promotions.segment_members')->insert(['segment_id' => $segment, 'customer_id' => $customer, 'added_at' => CarbonImmutable::now()]);

    DB::table('promotions.segments')->where('id', $segment)->delete();

    expect(DB::table('promotions.segment_members')->count())->toBe(0);
});
