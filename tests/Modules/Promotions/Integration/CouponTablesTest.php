<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the coupons' code (promotions.md §5; handoff §5.3): every named
| CHECK, key, unique index and trigger of the coupon tables, each refusing a row written past the code —
| each row breaking that one rule only (lesson 112), a CHECK on a nullable column broken with the
| column left NULL (lessons 35, 162), every kind column refusing an unknown kind.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function couponTablesUlid(): string
{
    return strtolower((string) Str::ulid());
}

/**
 * One valid row in every table: a percentage coupon of KSA with an include rule, assigned to a
 * customer who used it on an order; a fixed coupon of KSA; a coupon of Egypt.
 *
 * @return array<string, string>
 */
function couponTablesRows(): array
{
    $customer = Fx::customer('coupon-one@example.test');
    $ready = Px::ready();
    $category = Px::category('Doors');
    $percent = Pr::coupon('PERCENT-15', rules: [['INCLUDE', 'product', $ready['product']]]);
    $fixed = Pr::coupon('FIXED-50', with: ['discount_kind' => 'FIXED', 'percent' => null, 'amount_minor' => 50_00]);
    $egypt = Pr::coupon('EGYPT-10', 'eg', with: ['currency_code' => Pr::currency('eg')]);
    Pr::assign($percent, $customer);
    $order = Pr::used($percent, $customer);
    DB::table('promotions.order_facts')->insert(['order_id' => $order, 'store_id' => Fx::storeId('sa'), 'customer_id' => $customer, 'placed_at' => CarbonImmutable::now(), 'ceiling_percent' => 30]);

    return [
        'sa' => Fx::storeId('sa'), 'eg' => Fx::storeId('eg'), 'customer' => $customer, 'product' => $ready['product'], 'category' => $category,
        'percent' => $percent, 'fixed' => $fixed, 'egypt' => $egypt, 'order' => $order,
    ];
}

/**
 * @param  array<string, string>  $r
 * @param  array<string, mixed>  $columns
 */
function couponTablesUse(array $r, array $columns = []): void
{
    DB::table('promotions.coupon_uses')->insert([
        'coupon_id' => $r['percent'], 'store_id' => $r['sa'], 'customer_id' => $r['customer'], 'order_id' => couponTablesUlid(),
        'order_number' => 'SA-20001', 'discount_minor' => 1_00, 'used_at' => CarbonImmutable::now(), ...$columns,
    ]);
}

it('takes a whole valid set of rows: every table holds its row', function () {
    $r = couponTablesRows();

    foreach (['coupons' => 3, 'coupon_rules' => 1, 'coupon_assignments' => 1, 'coupon_uses' => 1, 'order_facts' => 1] as $table => $count) {
        expect(DB::table("promotions.{$table}")->count())->toBe($count);
    }

    // A staff edit may lower a use's discount, to 0 even — the use still counts (§1.3).
    $edit = couponTablesUlid();
    DB::table('promotions.coupon_uses')->where('order_id', $r['order'])->update(['discount_minor' => 0, 'last_edit_id' => $edit]);
    // max_uses_total set to exactly the uses ends the coupon (§1.1); a percentage may be 100 and carry a cap.
    DB::table('promotions.coupons')->where('id', $r['percent'])->update(['max_uses_total' => 1, 'percent' => 100, 'cap_minor' => 1]);
    // A rule may name a category or a brand instead.
    DB::table('promotions.coupon_rules')->insert(['id' => couponTablesUlid(), 'coupon_id' => $r['percent'], 'kind' => 'EXCLUDE', 'category_id' => $r['category']]);
    // An unused coupon's delete takes its assignments with it (§1.1).
    $unused = Pr::coupon('UNUSED-1');
    Pr::assign($unused, $r['customer']);
    DB::table('promotions.coupons')->where('id', $unused)->delete();

    expect(DB::table('promotions.coupon_uses')->where('order_id', $r['order'])->value('discount_minor'))->toBe(0)
        ->and(DB::table('promotions.coupon_rules')->count())->toBe(2)
        ->and(DB::table('promotions.coupon_assignments')->where('coupon_id', $unused)->exists())->toBeFalse();
});

it('refuses what the code would never write', function (Closure $write, string $constraint) {
    $r = couponTablesRows();

    // The constraint's name as PostgreSQL quotes it, so one named inside another cannot answer for
    // it; a message without a name is matched as written.
    expect(fn () => DB::transaction(function () use ($write, $r): bool {
        $write($r);

        return true;
    }))->toThrow(QueryException::class, str_contains($constraint, ' ') ? $constraint : "\"{$constraint}\"");
})->with([
    // coupons
    'a code used twice in a store' => [fn (array $r) => Pr::coupon('PERCENT-15'), 'coupons_store_code'],
    'a code in lower case' => [fn (array $r) => Pr::coupon('lower-case'), 'coupons_code'],
    'a code too short' => [fn (array $r) => Pr::coupon('AB'), 'coupons_code'],
    'a code with a space' => [fn (array $r) => Pr::coupon('TWO WORDS'), 'coupons_code'],
    'an unknown eligibility' => [fn (array $r) => Pr::coupon('NEW-1', with: ['eligibility' => 'EVERYONE']), 'coupons_eligibility'],
    // An unknown kind fits neither shape of coupons_discount, which PostgreSQL checks first (CHECKs run
    // in name order): coupons_discount_kind is proved present below.
    'an unknown discount kind' => [fn (array $r) => Pr::coupon('NEW-1', with: ['discount_kind' => 'POINTS']), 'coupons_discount'],
    'a percentage with no percent' => [fn (array $r) => Pr::coupon('NEW-1', with: ['percent' => null]), 'coupons_discount'],
    'a percentage of 0' => [fn (array $r) => Pr::coupon('NEW-1', with: ['percent' => 0]), 'coupons_discount'],
    'a percentage above 100' => [fn (array $r) => Pr::coupon('NEW-1', with: ['percent' => 101]), 'coupons_discount'],
    'a percentage with an amount' => [fn (array $r) => Pr::coupon('NEW-1', with: ['amount_minor' => 5_00]), 'coupons_discount'],
    'a fixed coupon with no amount' => [fn (array $r) => Pr::coupon('NEW-1', with: ['discount_kind' => 'FIXED', 'percent' => null]), 'coupons_discount'],
    'a fixed amount of 0' => [fn (array $r) => Pr::coupon('NEW-1', with: ['discount_kind' => 'FIXED', 'percent' => null, 'amount_minor' => 0]), 'coupons_discount'],
    'a fixed coupon with a percent' => [fn (array $r) => Pr::coupon('NEW-1', with: ['discount_kind' => 'FIXED', 'amount_minor' => 5_00]), 'coupons_discount'],
    'a fixed coupon with a cap' => [fn (array $r) => Pr::coupon('NEW-1', with: ['discount_kind' => 'FIXED', 'percent' => null, 'amount_minor' => 5_00, 'cap_minor' => 1_00]), 'coupons_discount'],
    'a cap of 0' => [fn (array $r) => Pr::coupon('NEW-1', with: ['cap_minor' => 0]), 'coupons_cap'],
    'a minimum of 0' => [fn (array $r) => Pr::coupon('NEW-1', with: ['minimum_minor' => 0]), 'coupons_minimum'],
    'an end at its start' => [fn (array $r) => Pr::coupon('NEW-1', with: ['starts_at' => '2026-10-01 00:00:00+00', 'ends_at' => '2026-10-01 00:00:00+00']), 'coupons_dates'],
    'no uses per customer' => [fn (array $r) => Pr::coupon('NEW-1', with: ['max_uses_per_customer' => 0]), 'coupons_max_per_customer'],
    'no uses in all' => [fn (array $r) => Pr::coupon('NEW-1', with: ['max_uses_total' => 0]), 'coupons_max_total'],
    'uses below 0' => [fn (array $r) => Pr::coupon('NEW-1', with: ['uses_total' => -1]), 'coupons_uses'],
    'more uses than allowed' => [fn (array $r) => DB::table('promotions.coupons')->where('id', $r['percent'])->update(['max_uses_total' => 1, 'uses_total' => 2]), 'coupons_uses'],
    'no sale mode' => [fn (array $r) => Pr::coupon('NEW-1', with: ['for_retail' => false, 'for_wholesale' => false]), 'coupons_sale_modes'],
    'no buyer' => [fn (array $r) => Pr::coupon('NEW-1', with: ['for_public' => false, 'for_company' => false]), 'coupons_audiences'],
    'a coupon in no store' => [fn (array $r) => DB::table('promotions.coupons')->where('id', $r['fixed'])->update(['store_id' => couponTablesUlid()]), 'coupons_store'],
    // coupon_rules
    'an unknown rule kind' => [fn (array $r) => DB::table('promotions.coupon_rules')->insert(['id' => couponTablesUlid(), 'coupon_id' => $r['fixed'], 'kind' => 'MAYBE', 'brand_id' => DB::table('catalog.brands')->value('id')]), 'coupon_rules_kind'],
    'a rule naming nothing' => [fn (array $r) => DB::table('promotions.coupon_rules')->insert(['id' => couponTablesUlid(), 'coupon_id' => $r['fixed'], 'kind' => 'INCLUDE']), 'coupon_rules_one_target'],
    'a rule naming two things' => [fn (array $r) => DB::table('promotions.coupon_rules')->insert(['id' => couponTablesUlid(), 'coupon_id' => $r['fixed'], 'kind' => 'INCLUDE', 'product_id' => $r['product'], 'category_id' => $r['category']]), 'coupon_rules_one_target'],
    'a rule given twice' => [fn (array $r) => DB::table('promotions.coupon_rules')->insert(['id' => couponTablesUlid(), 'coupon_id' => $r['percent'], 'kind' => 'INCLUDE', 'product_id' => $r['product']]), 'coupon_rules_unique'],
    'a rule naming no product' => [fn (array $r) => DB::table('promotions.coupon_rules')->insert(['id' => couponTablesUlid(), 'coupon_id' => $r['fixed'], 'kind' => 'INCLUDE', 'product_id' => couponTablesUlid()]), 'coupon_rules_product'],
    'a rule naming no category' => [fn (array $r) => DB::table('promotions.coupon_rules')->insert(['id' => couponTablesUlid(), 'coupon_id' => $r['fixed'], 'kind' => 'INCLUDE', 'category_id' => couponTablesUlid()]), 'coupon_rules_category'],
    'a rule naming no brand' => [fn (array $r) => DB::table('promotions.coupon_rules')->insert(['id' => couponTablesUlid(), 'coupon_id' => $r['fixed'], 'kind' => 'INCLUDE', 'brand_id' => couponTablesUlid()]), 'coupon_rules_brand'],
    'deleting a product a rule names' => [fn (array $r) => DB::table('catalog.products')->where('id', $r['product'])->delete(), 'coupon_rules_product'],
    // coupon_assignments
    'an assignment given twice' => [fn (array $r) => Pr::assign($r['percent'], $r['customer']), 'coupon_assignments_pkey'],
    'an assignment of another store\'s coupon' => [fn (array $r) => Pr::assign($r['egypt'], $r['customer']), 'coupon_assignments_coupon'],
    'an assignment to no customer' => [fn (array $r) => Pr::assign($r['fixed'], couponTablesUlid()), 'coupon_assignments_customer'],
    // coupon_uses
    'a second coupon on one order' => [fn (array $r) => couponTablesUse($r, ['coupon_id' => $r['fixed'], 'order_id' => $r['order']]), 'coupon_uses_order'],
    'a use of another store\'s coupon' => [fn (array $r) => couponTablesUse($r, ['coupon_id' => $r['egypt']]), 'coupon_uses_coupon'],
    'a use by no customer' => [fn (array $r) => couponTablesUse($r, ['customer_id' => couponTablesUlid()]), 'coupon_uses_customer'],
    'a discount below 0' => [fn (array $r) => couponTablesUse($r, ['discount_minor' => -1]), 'coupon_uses_discount'],
    'a used coupon deleted' => [fn (array $r) => DB::table('promotions.coupons')->where('id', $r['percent'])->delete(), 'coupon_uses_coupon'],
    'a use deleted' => [fn (array $r) => DB::table('promotions.coupon_uses')->where('order_id', $r['order'])->delete(), 'a use is kept'],
    'a use emptied' => [fn (array $r) => DB::statement('TRUNCATE promotions.coupon_uses CASCADE'), 'a use is kept'],
    'a discount raised' => [fn (array $r) => DB::table('promotions.coupon_uses')->where('order_id', $r['order'])->update(['discount_minor' => 2_00, 'last_edit_id' => couponTablesUlid()]), 'a use is kept'],
    'a discount lowered with no edit' => [fn (array $r) => DB::table('promotions.coupon_uses')->where('order_id', $r['order'])->update(['discount_minor' => 50]), 'a use is kept'],
    'a use moved to another order' => [fn (array $r) => DB::table('promotions.coupon_uses')->where('order_id', $r['order'])->update(['order_id' => couponTablesUlid(), 'last_edit_id' => couponTablesUlid()]), 'a use is kept'],
    // order_facts
    'a ceiling above 100' => [fn (array $r) => DB::table('promotions.order_facts')->insert(['order_id' => couponTablesUlid(), 'store_id' => $r['sa'], 'customer_id' => $r['customer'], 'placed_at' => CarbonImmutable::now(), 'ceiling_percent' => 101]), 'order_facts_ceiling'],
    'a ceiling below 0' => [fn (array $r) => DB::table('promotions.order_facts')->insert(['order_id' => couponTablesUlid(), 'store_id' => $r['sa'], 'customer_id' => $r['customer'], 'placed_at' => CarbonImmutable::now(), 'ceiling_percent' => -1]), 'order_facts_ceiling'],
    'an order recorded twice' => [fn (array $r) => DB::table('promotions.order_facts')->insert(['order_id' => $r['order'], 'store_id' => $r['sa'], 'customer_id' => $r['customer'], 'placed_at' => CarbonImmutable::now(), 'ceiling_percent' => 30]), 'order_facts_pkey'],
    'an order of no customer' => [fn (array $r) => DB::table('promotions.order_facts')->insert(['order_id' => couponTablesUlid(), 'store_id' => $r['sa'], 'customer_id' => couponTablesUlid(), 'placed_at' => CarbonImmutable::now(), 'ceiling_percent' => 30]), 'order_facts_customer'],
    'an order in no store' => [fn (array $r) => DB::table('promotions.order_facts')->insert(['order_id' => couponTablesUlid(), 'store_id' => couponTablesUlid(), 'customer_id' => $r['customer'], 'placed_at' => CarbonImmutable::now(), 'ceiling_percent' => 30]), 'order_facts_store'],
    'a fact changed' => [fn (array $r) => DB::table('promotions.order_facts')->where('order_id', $r['order'])->update(['ceiling_percent' => 20]), 'order_facts never change'],
    'a fact deleted' => [fn (array $r) => DB::table('promotions.order_facts')->where('order_id', $r['order'])->delete(), 'order_facts never change'],
    'the facts emptied' => [fn (array $r) => DB::statement('TRUNCATE promotions.order_facts'), 'order_facts never change'],
]);

it('refuses a code twice in a store whatever its case — the code stores it upper case, the database only takes upper case', function () {
    couponTablesRows();

    expect(fn () => DB::transaction(fn () => Pr::coupon('percent-15')))->toThrow(QueryException::class, '"coupons_code"')
        ->and(Pr::coupon('PERCENT-15', 'eg', with: ['currency_code' => Pr::currency('eg')]))->toBeString();
});

it('holds the discount kind to its own list of values', function () {
    $definition = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'coupons_discount_kind' AND conrelid = 'promotions.coupons'::regclass");

    expect((string) ($definition->def ?? ''))->toContain("'PERCENT'")->toContain("'FIXED'")->toContain('discount_kind');
});
