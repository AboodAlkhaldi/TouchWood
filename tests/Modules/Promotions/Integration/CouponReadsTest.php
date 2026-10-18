<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\ListCouponAssignments\ListCouponAssignments;
use Modules\Promotions\Application\Query\ListCouponAssignments\ListCouponAssignmentsHandler;
use Modules\Promotions\Application\Query\ListCoupons\ListCoupons;
use Modules\Promotions\Application\Query\ListCoupons\ListCouponsHandler;
use Modules\Promotions\Application\Query\ListCouponUses\ListCouponUses;
use Modules\Promotions\Application\Query\ListCouponUses\ListCouponUsesHandler;
use Modules\Promotions\Application\Query\ViewCoupon\ViewCoupon;
use Modules\Promotions\Application\Query\ViewCoupon\ViewCouponHandler;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Public\Enums\CouponState;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| The staff screens' coupon reads (promotions.md §3, §4): a store's coupons with their state now, one
| coupon in full with its lists named, its uses by the order's public number, its assignments — for
| whoever holds the view permission or a manage permission in the store; another store's coupon not
| found; an off store for Super Admins alone; a page at a time.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  list<string>  $stores
 */
function couponReader(string $permission = PromotionsPermissions::VIEW, array $stores = ['sa']): void
{
    Fx::actAsStaff(Fx::staffWith([$permission], $stores));
}

/**
 * @return list<string>
 */
function listedCodes(?CouponState $state = null, ?string $codeStart = null, string $storeCode = 'sa'): array
{
    return array_map(static fn ($row): string => $row->code, app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId($storeCode), $state, $codeStart))->rows);
}

describe('a store\'s coupons', function () {
    it('lists them newest first, a page at a time, with their state now', function () {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        Pr::coupon('ACTIVE-1', with: ['created_at' => $now->subMinutes(5)]);
        Pr::coupon('LATER-1', with: ['starts_at' => $now->addDay(), 'created_at' => $now->subMinutes(4)]);
        Pr::coupon('OFF-1', with: ['active' => false, 'created_at' => $now->subMinutes(3)]);
        Pr::coupon('PAST-1', with: ['ends_at' => $now, 'created_at' => $now->subMinutes(2)]);
        Pr::coupon('GONE-1', with: ['max_uses_total' => 2, 'uses_total' => 2, 'active' => false, 'created_at' => $now->subMinute()]);
        Pr::coupon('EGYPT-1', 'eg');
        couponReader();

        $first = app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa'), perPage: 3));
        $second = app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa'), beforeAt: $first->nextAt, beforeId: $first->nextId, perPage: 3));

        expect(array_map(static fn ($row): array => [$row->code, $row->state], $first->rows))->toBe([
            ['GONE-1', CouponState::Ended], ['PAST-1', CouponState::Ended], ['OFF-1', CouponState::Inactive],
        ])
            ->and(array_map(static fn ($row): array => [$row->code, $row->state], $second->rows))->toBe([
                ['LATER-1', CouponState::Scheduled], ['ACTIVE-1', CouponState::Active],
            ])
            ->and($second->nextAt)->toBeNull()
            ->and($second->nextId)->toBeNull()
            ->and($first->rows[0]->percent)->toBe(15)
            ->and($first->rows[0]->usesTotal)->toBe(2);
    });

    it('filters by state now and by the start of the code', function () {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        Pr::coupon('SUMMER-1');
        Pr::coupon('SUMMER-2', with: ['starts_at' => $now->addDay()]);
        Pr::coupon('WINTER-1', with: ['active' => false]);
        Pr::coupon('PAST-1', with: ['ends_at' => $now]);
        Pr::coupon('OFF-PAST', with: ['starts_at' => $now->subDays(3), 'ends_at' => $now->subDay(), 'active' => false]);
        Pr::coupon('USED-1', with: ['max_uses_total' => 1, 'uses_total' => 1]);
        Pr::coupon('NOT-YET', with: ['starts_at' => $now->addSecond(), 'active' => false]);
        couponReader();
        // A coupon starting this very second is active, in the list's filter and on its row.
        Pr::coupon('NOW-1', with: ['starts_at' => $now]);
        expect(listedCodes(CouponState::Active))->toEqualCanonicalizing(['SUMMER-1', 'NOW-1'])
            ->and(listedCodes(CouponState::Scheduled))->toBe(['SUMMER-2'])
            ->and(app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa'), codeStart: 'NOW'))->rows[0]->state)->toBe(CouponState::Active);
        DB::table('promotions.coupons')->where('code', 'NOW-1')->delete();

        expect(listedCodes(CouponState::Active))->toBe(['SUMMER-1'])
            ->and(listedCodes(CouponState::Scheduled))->toBe(['SUMMER-2'])
            ->and(listedCodes(CouponState::Inactive))->toEqualCanonicalizing(['WINTER-1', 'NOT-YET'])
            ->and(listedCodes(CouponState::Ended))->toEqualCanonicalizing(['PAST-1', 'OFF-PAST', 'USED-1'])
            ->and(listedCodes(codeStart: 'summer'))->toEqualCanonicalizing(['SUMMER-1', 'SUMMER-2'])
            ->and(listedCodes(CouponState::Active, 'SUM'))->toBe(['SUMMER-1'])
            ->and(listedCodes(codeStart: '%'))->toBe([])
            ->and(listedCodes(codeStart: 'S_MMER'))->toBe([])
            // Read as a code is typed: spaces around it, Arabic digits as 0-9 (owner, 2026-10-06).
            ->and(listedCodes(codeStart: '  summer-1 '))->toBe(['SUMMER-1'])
            ->and(listedCodes(codeStart: 'SUMMER-١'))->toBe(['SUMMER-1'])
            ->and(listedCodes(codeStart: '   '))->toHaveCount(7);
    });

    it('reads a page in one query', function () {
        foreach (range(1, 12) as $n) {
            Pr::coupon("CODE-{$n}");
        }
        couponReader();
        app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa')));

        $queries = Cx::recordQueries();
        app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa')));

        expect(array_values(array_filter($queries->getArrayCopy(), static fn (array $q): bool => str_contains($q['sql'], '"promotions".'))))->toHaveCount(1);
    });
});

describe('who may read', function () {
    it('lets the view permission or any manage permission read, in their own stores only', function (string $permission) {
        Pr::coupon();
        $egypt = Pr::coupon('EGYPT-1', 'eg');
        couponReader($permission, ['sa']);

        expect(listedCodes())->toBe(['SUMMER-15'])
            ->and(fn () => listedCodes(storeCode: 'eg'))->toThrow(Unauthorized::class)
            ->and(fn () => app(ViewCouponHandler::class)->handle(new ViewCoupon($egypt)))->toThrow(CouponNotFound::class);
    })->with([PromotionsPermissions::VIEW, PromotionsPermissions::COUPON_MANAGE, PromotionsPermissions::SEGMENT_MANAGE, PromotionsPermissions::GIFT_MANAGE]);

    it('refuses anyone with none of them', function (Closure $read) {
        $coupon = Pr::coupon();
        Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['sa']));

        expect(fn () => $read($coupon))->toThrow(Unauthorized::class);
    })->with([
        'the list' => [fn () => listedCodes()],
        'a coupon' => [fn (string $id) => app(ViewCouponHandler::class)->handle(new ViewCoupon($id))],
        'its uses' => [fn (string $id) => app(ListCouponUsesHandler::class)->handle(new ListCouponUses($id))],
        'its assignments' => [fn (string $id) => app(ListCouponAssignmentsHandler::class)->handle(new ListCouponAssignments($id))],
    ]);

    it('answers an unknown coupon, or one of a store not covered, as not found', function (Closure $read) {
        $egypt = Pr::coupon('EGYPT-1', 'eg');
        couponReader();

        expect(fn () => $read($egypt))->toThrow(CouponNotFound::class)
            ->and(fn () => $read(strtolower((string) Str::ulid())))->toThrow(CouponNotFound::class)
            ->and(fn () => $read('not-an-id'))->toThrow(CouponNotFound::class)
            ->and(fn () => app(ListCouponsHandler::class)->handle(new ListCoupons('not-a-store')))->toThrow(Unauthorized::class);
    })->with([
        'a coupon' => [fn (string $id) => app(ViewCouponHandler::class)->handle(new ViewCoupon($id))],
        'its uses' => [fn (string $id) => app(ListCouponUsesHandler::class)->handle(new ListCouponUses($id))],
        'its assignments' => [fn (string $id) => app(ListCouponAssignmentsHandler::class)->handle(new ListCouponAssignments($id))],
    ]);

    it('keeps a switched-off store\'s coupons for Super Admins alone', function () {
        $egypt = Pr::coupon('EGYPT-1', 'eg');
        $eg = Fx::storeId('eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        couponReader(stores: ['*']);

        expect(fn () => app(ListCouponsHandler::class)->handle(new ListCoupons($eg)))->toThrow(Unauthorized::class)
            ->and(fn () => app(ViewCouponHandler::class)->handle(new ViewCoupon($egypt)))->toThrow(Unauthorized::class)
            ->and(fn () => app(ListCouponUsesHandler::class)->handle(new ListCouponUses($egypt)))->toThrow(Unauthorized::class)
            ->and(fn () => app(ListCouponAssignmentsHandler::class)->handle(new ListCouponAssignments($egypt)))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(app(ListCouponsHandler::class)->handle(new ListCoupons($eg))->rows)->toHaveCount(1)
            ->and(app(ViewCouponHandler::class)->handle(new ViewCoupon($egypt))->coupon->code)->toBe('EGYPT-1');
    });
});

describe('one coupon', function () {
    it('shows it in full, its lists named, and how many customers have it', function () {
        $product = Px::ready()['product'];
        $category = Px::category('Doors');
        $brand = Px::brand('Hafele');
        $coupon = Pr::coupon('VIP-10', with: ['eligibility' => 'ASSIGNED', 'cap_minor' => 50_00, 'minimum_minor' => 100_00, 'max_uses_per_customer' => 2, 'for_company' => false], rules: [
            ['INCLUDE', 'product', $product], ['INCLUDE', 'category', $category], ['EXCLUDE', 'brand', $brand],
        ]);
        DB::table('promotions.coupon_rules')->insert(['id' => strtolower((string) Str::ulid()), 'coupon_id' => $coupon, 'kind' => 'EXCLUDE', 'product_id' => $product]);
        Pr::assign($coupon, Fx::customer());
        Pr::assign($coupon, Fx::customer('omar@example.test'));
        couponReader(PromotionsPermissions::COUPON_MANAGE);

        $view = app(ViewCouponHandler::class)->handle(new ViewCoupon(strtoupper($coupon)));
        $named = array_map(static fn ($rule): array => [$rule->kind, $rule->target, $rule->targetId, $rule->nameEn !== null], $view->rules);

        expect($view->coupon->code)->toBe('VIP-10')
            ->and($view->coupon->state)->toBe(CouponState::Active)
            ->and([$view->cap?->minorUnits, $view->minimum?->minorUnits, $view->maxUsesPerCustomer, $view->forPublic, $view->forCompany])->toBe([50_00, 100_00, 2, true, false])
            ->and([$view->cap?->currencyCode, $view->coupon->currencyCode])->toBe([Pr::currency(), Pr::currency()])
            ->and($view->assignedCount)->toBe(2)
            ->and($named)->toContain(['INCLUDE', 'product', $product, true], ['INCLUDE', 'category', $category, true], ['EXCLUDE', 'brand', $brand, true])
            ->and(count($view->rules))->toBe(4);
    });
});

describe('its uses and assignments', function () {
    it('lists the uses by the order\'s public number and the customer\'s name, newest first, a page at a time', function () {
        $coupon = Pr::coupon();
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        $now = CarbonImmutable::now()->startOfSecond();
        foreach ([[$sara, 1_00, 3], [$omar, 2_00, 2], [$sara, 3_00, 1]] as [$customer, $discount, $minutesAgo]) {
            // A use is never updated (its trigger): written at its own moment.
            CarbonImmutable::setTestNow($now->subMinutes($minutesAgo));
            Pr::used($coupon, $customer, discountMinor: $discount);
        }
        CarbonImmutable::setTestNow();
        DB::table('access.customers')->where('id', $omar)->update(['anonymized_at' => now()]);
        couponReader();

        $first = app(ListCouponUsesHandler::class)->handle(new ListCouponUses($coupon, perPage: 2));
        $second = app(ListCouponUsesHandler::class)->handle(new ListCouponUses($coupon, $first->nextAt, $first->nextId, 2));

        expect(array_map(static fn ($row): array => [$row->customer->id, $row->discount->minorUnits], [...$first->rows, ...$second->rows]))
            ->toBe([[$sara, 3_00], [$omar, 2_00], [$sara, 1_00]])
            ->and($first->rows[0]->orderNumber)->toStartWith('SA-')
            ->and([$first->rows[0]->customer->firstName, $first->rows[0]->customer->lastName])->toBe(['Sara', 'Ali'])
            ->and($first->rows[1]->customer->anonymized)->toBeTrue()
            ->and($second->nextId)->toBeNull();
    });

    it('lists the assignments with their segment and uses, newest first, a page at a time', function () {
        $coupon = Pr::coupon(with: ['eligibility' => 'ASSIGNED']);
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        $segment = strtolower((string) Str::ulid());
        DB::table('promotions.segments')->insert([
            'id' => $segment, 'store_id' => Fx::storeId('sa'), 'name_ar' => 'الأوفياء', 'name_en' => 'Loyal', 'type' => 'STATIC', 'audience' => 'INDIVIDUAL',
            'rule' => null, 'rule_months' => null, 'rule_orders' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $now = CarbonImmutable::now()->startOfSecond();
        DB::table('promotions.coupon_assignments')->insert([
            ['coupon_id' => $coupon, 'store_id' => Fx::storeId('sa'), 'customer_id' => $sara, 'segment_id' => $segment, 'assigned_at' => $now->subMinute()],
            ['coupon_id' => $coupon, 'store_id' => Fx::storeId('sa'), 'customer_id' => $omar, 'segment_id' => null, 'assigned_at' => $now],
        ]);
        Pr::used($coupon, $sara);
        couponReader();

        $first = app(ListCouponAssignmentsHandler::class)->handle(new ListCouponAssignments($coupon, perPage: 1));
        $second = app(ListCouponAssignmentsHandler::class)->handle(new ListCouponAssignments($coupon, $first->nextAt, $first->nextCustomerId, 1));

        expect([$first->rows[0]->customer->id, $first->rows[0]->segmentId, $first->rows[0]->uses])->toBe([$omar, null, 0])
            ->and([$second->rows[0]->customer->id, $second->rows[0]->segmentNameEn, $second->rows[0]->uses])->toBe([$sara, 'Loyal', 1])
            ->and($second->nextCustomerId)->toBeNull();
    });
});

describe('pages', function () {
    it('walks coupons made in the same second by their id, never skipping or repeating one', function () {
        $now = CarbonImmutable::now()->startOfSecond();
        $made = array_map(static fn (int $n): string => Pr::coupon("SAME-{$n}", with: ['created_at' => $now]), range(1, 5));
        couponReader();
        $seen = [];
        $page = app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa'), perPage: 2));

        while (true) {
            $seen = [...$seen, ...array_map(static fn ($row): string => $row->id, $page->rows)];

            if ($page->nextId === null) {
                break;
            }

            $page = app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa'), beforeAt: $page->nextAt, beforeId: $page->nextId, perPage: 2));
        }

        $expected = $made;
        rsort($expected);

        expect($seen)->toBe($expected);
    });

    it('keeps a page between 1 and 50 rows whatever is asked', function () {
        foreach (range(1, 51) as $n) {
            Pr::coupon("MANY-{$n}");
        }
        couponReader();

        expect(app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa'), perPage: 0))->rows)->toHaveCount(1)
            ->and(app(ListCouponsHandler::class)->handle(new ListCoupons(Fx::storeId('sa'), perPage: 500))->rows)->toHaveCount(50);
    });

    it('reads a page of uses or assignments in the same queries however many rows it holds', function () {
        $few = Pr::coupon('FEW-1', with: ['eligibility' => 'ASSIGNED']);
        $many = Pr::coupon('MANY-1', with: ['eligibility' => 'ASSIGNED']);
        $customers = array_map(static fn (int $n): string => Fx::customer("c{$n}@example.test"), range(1, 6));
        Pr::used($few, $customers[0]);
        Pr::assign($few, $customers[0]);

        foreach ($customers as $customer) {
            Pr::used($many, $customer);
            Pr::assign($many, $customer);
        }
        couponReader();

        $count = static function (Closure $read): int {
            $read();
            $queries = Cx::recordQueries();
            $read();

            return count($queries);
        };

        expect($count(fn () => app(ListCouponUsesHandler::class)->handle(new ListCouponUses($many))))
            ->toBe($count(fn () => app(ListCouponUsesHandler::class)->handle(new ListCouponUses($few))))
            ->and($count(fn () => app(ListCouponAssignmentsHandler::class)->handle(new ListCouponAssignments($many))))
            ->toBe($count(fn () => app(ListCouponAssignmentsHandler::class)->handle(new ListCouponAssignments($few))));
    });
});
