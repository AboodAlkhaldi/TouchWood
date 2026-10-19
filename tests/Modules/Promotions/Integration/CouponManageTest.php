<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\CategoryDto;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Promotions\Application\Command\ChangeCoupon\ChangeCoupon;
use Modules\Promotions\Application\Command\ChangeCoupon\ChangeCouponHandler;
use Modules\Promotions\Application\Command\CouponFields;
use Modules\Promotions\Application\Command\CreateCoupon\CreateCoupon;
use Modules\Promotions\Application\Command\CreateCoupon\CreateCouponHandler;
use Modules\Promotions\Application\Command\DeleteCoupon\DeleteCoupon;
use Modules\Promotions\Application\Command\DeleteCoupon\DeleteCouponHandler;
use Modules\Promotions\Application\Command\SwitchCoupon\SwitchCoupon;
use Modules\Promotions\Application\Command\SwitchCoupon\SwitchCouponHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Exception\CodeTaken;
use Modules\Promotions\Domain\Exception\CouponInUse;
use Modules\Promotions\Domain\Exception\CouponNotFound;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Coupons for staff (promotions.md §1.1, §1.2, §1.8, §3, §7; the scenarios of §8): creating one with a
| code read as typed and unique in the store, its terms and lists checked; changing it for every later
| use; switching it off and on; deleting one never used — each audited, each in the staff member's own
| stores, an off store's for Super Admins alone.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * A 15% coupon for everyone from today, unless told otherwise.
 *
 * @param  array<string, mixed>  $with  CouponFields' arguments by name
 */
function couponFields(array $with = []): CouponFields
{
    return new CouponFields(...[
        'nameAr' => 'كوبون الصيف', 'nameEn' => 'Summer', 'eligibility' => 'PUBLIC', 'discountKind' => 'PERCENT',
        'percent' => 15, 'capMinor' => null, 'amountMinor' => null, 'minimumMinor' => null,
        'startsAt' => CarbonImmutable::now()->subMinute(), 'endsAt' => null, 'maxUsesPerCustomer' => null, 'maxUsesTotal' => null,
        'allowWithPoints' => true, 'onReducedLines' => false, 'forRetail' => true, 'forWholesale' => true,
        'forPublic' => true, 'forCompany' => true, 'rules' => [],
        ...$with,
    ]);
}

/**
 * @param  list<string>  $stores
 */
function couponManager(array $stores = ['sa']): string
{
    $staff = Fx::staffWith([PromotionsPermissions::COUPON_MANAGE], $stores);
    Fx::actAsStaff($staff);

    return $staff;
}

function couponCreate(string $code = 'SUMMER-15', ?CouponFields $fields = null, string $storeCode = 'sa'): string
{
    return app(CreateCouponHandler::class)->handle(new CreateCoupon(Fx::storeId($storeCode), $code, $fields ?? couponFields()));
}

/**
 * @return array<string, mixed>
 */
function couponAudit(string $action): array
{
    $row = DB::table('platform.audit_entries')->where('action', $action)->orderByDesc('id')->first() ?? throw new RuntimeException("No {$action} in the audit log.");

    return ['subject_id' => $row->subject_id, 'store_id' => $row->store_id, 'changes' => json_decode((string) $row->changes, true)];
}

function couponRefusalNow(string $customer, string $code): ?CouponRefusal
{
    [$variant] = Px::ready()['variants'];

    return Pr::api()->applyCoupon(new CouponRequest(Fx::storeId('sa'), $code, $customer, Audience::Public, Pr::prices([Pr::line($variant, 200_00)])))->refusal;
}

describe('creating a coupon', function () {
    it('creates it with its terms and lists, the code upper case, audited', function () {
        $staff = couponManager();
        $category = Px::category('Doors');
        $brand = Px::brand('Hafele');
        $product = Px::ready()['product'];

        $id = couponCreate('  summer-٢٠٢٦ ', couponFields(['capMinor' => 100_00, 'minimumMinor' => 50_00, 'rules' => [
            ['kind' => 'INCLUDE', 'target' => 'category', 'id' => strtoupper($category)],
            ['kind' => 'EXCLUDE', 'target' => 'brand', 'id' => $brand],
            ['kind' => 'INCLUDE', 'target' => 'product', 'id' => $product],
            ['kind' => 'INCLUDE', 'target' => 'category', 'id' => $category],
        ]]));
        $row = DB::table('promotions.coupons')->where('id', $id)->first();
        $audit = couponAudit('promotions.coupon.created');

        expect($row?->code)->toBe('SUMMER-2026')
            ->and($row?->store_id)->toBe(Fx::storeId('sa'))
            ->and($row?->currency_code)->toBe(Pr::currency())
            ->and((int) $row?->percent)->toBe(15)
            ->and((int) $row?->cap_minor)->toBe(100_00)
            ->and((int) $row?->uses_total)->toBe(0)
            ->and((bool) $row?->active)->toBeTrue()
            // The same rule twice is one rule.
            ->and(DB::table('promotions.coupon_rules')->where('coupon_id', $id)->count())->toBe(3)
            ->and($audit['subject_id'])->toBe($id)
            ->and($audit['store_id'])->toBe(Fx::storeId('sa'))
            ->and($audit['changes']['code'])->toBe([null, 'SUMMER-2026'])
            ->and($audit['changes']['percent'])->toBe([null, 15])
            ->and(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.created')->value('actor_id'))->toBe($staff);
    });

    it('keeps a fixed coupon\'s percentage and cap out, and a percentage coupon\'s amount', function () {
        couponManager();

        $fixed = couponCreate('FIXED-50', couponFields(['discountKind' => 'FIXED', 'amountMinor' => 50_00, 'percent' => 15, 'capMinor' => 10_00]));
        $percent = couponCreate('PCT-15', couponFields(['amountMinor' => 50_00]));

        expect(DB::table('promotions.coupons')->where('id', $fixed)->first(['percent', 'cap_minor', 'amount_minor']))->toEqual((object) ['percent' => null, 'cap_minor' => null, 'amount_minor' => 50_00])
            ->and(DB::table('promotions.coupons')->where('id', $percent)->value('amount_minor'))->toBeNull();
    });

    it('refuses a code another coupon of the store carries, whatever its case — and takes it in another store', function () {
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE], ['sa', 'eg']));
        couponCreate('SUMMER-15');

        $taken = Pr::thrown(fn () => couponCreate('summer-15'), CodeTaken::class);

        expect($taken->context())->toBe(['code' => 'SUMMER-15'])
            ->and(couponCreate('SUMMER-15', storeCode: 'eg'))->toBeString()
            ->and(DB::table('promotions.coupons')->count())->toBe(2);
    });

    it('refuses what can never be a coupon, naming the field', function (string $code, array $with, string $field) {
        couponManager();
        $draft = Px::product('Hinge');
        $offCategory = Px::category('Old');
        DB::table('catalog.categories')->where('id', $offCategory)->update(['is_active' => false]);
        $offBrand = Px::brand('Old');
        DB::table('catalog.brands')->where('id', $offBrand)->update(['is_active' => false]);
        $with = array_map(static fn (mixed $value): mixed => match ($value) {
            'DRAFT' => [['kind' => 'INCLUDE', 'target' => 'product', 'id' => $draft]],
            'OFF-CATEGORY' => [['kind' => 'EXCLUDE', 'target' => 'category', 'id' => $offCategory]],
            'OFF-BRAND' => [['kind' => 'INCLUDE', 'target' => 'brand', 'id' => $offBrand]],
            default => $value,
        }, $with);

        expect(Pr::thrown(fn () => couponCreate($code, couponFields($with)), InvalidPromotionAttribute::class)->attribute)->toBe($field)
            ->and(DB::table('promotions.coupons')->count())->toBe(0);
    })->with([
        'a code too short' => ['AB', [], 'code'],
        'a code with a space' => ['TWO WORDS', [], 'code'],
        'a code with a letter not Latin' => ['صيف-15', [], 'code'],
        'no Arabic name' => ['NEW-1', ['nameAr' => '  '], 'name'],
        'an English name too long' => ['NEW-1', ['nameEn' => str_repeat('a', 101)], 'name'],
        'an unknown eligibility' => ['NEW-1', ['eligibility' => 'EVERYONE'], 'eligibility'],
        'an unknown kind' => ['NEW-1', ['discountKind' => 'POINTS'], 'discount_kind'],
        'a percentage of 0' => ['NEW-1', ['percent' => 0], 'percent'],
        'a percentage above 100' => ['NEW-1', ['percent' => 101], 'percent'],
        'no percentage' => ['NEW-1', ['percent' => null], 'percent'],
        'a cap of 0' => ['NEW-1', ['capMinor' => 0], 'cap'],
        'a fixed amount of 0' => ['NEW-1', ['discountKind' => 'FIXED', 'amountMinor' => 0], 'amount'],
        'no fixed amount' => ['NEW-1', ['discountKind' => 'FIXED'], 'amount'],
        'a minimum of 0' => ['NEW-1', ['minimumMinor' => 0], 'minimum'],
        'an end at its start' => ['NEW-1', ['startsAt' => CarbonImmutable::parse('2026-11-01 00:00'), 'endsAt' => CarbonImmutable::parse('2026-11-01 00:00')], 'dates'],
        'no uses per customer' => ['NEW-1', ['maxUsesPerCustomer' => 0], 'max_uses'],
        'no uses in all' => ['NEW-1', ['maxUsesTotal' => 0], 'max_uses'],
        'more uses than the column holds' => ['NEW-1', ['maxUsesTotal' => 2_147_483_648], 'max_uses'],
        'an end before its start, given in another offset' => ['NEW-1', ['startsAt' => CarbonImmutable::parse('2026-11-01 12:00:00+03:00'), 'endsAt' => CarbonImmutable::parse('2026-11-01 09:00:00+00:00')], 'dates'],
        'no sale mode' => ['NEW-1', ['forRetail' => false, 'forWholesale' => false], 'sale_modes'],
        'no buyer' => ['NEW-1', ['forPublic' => false, 'forCompany' => false], 'buyers'],
        'the public only on wholesale lines only' => ['NEW-1', ['forCompany' => false, 'forRetail' => false], 'buyers'],
        'a rule of an unknown kind' => ['NEW-1', ['rules' => [['kind' => 'MAYBE', 'target' => 'brand', 'id' => '01k6abcdefghjkmnpqrstvwxyz']]], 'rules'],
        'a rule naming no id' => ['NEW-1', ['rules' => [['kind' => 'INCLUDE', 'target' => 'brand', 'id' => 'kitchens']]], 'rules'],
        'a rule naming an unknown brand' => ['NEW-1', ['rules' => [['kind' => 'INCLUDE', 'target' => 'brand', 'id' => '01k6abcdefghjkmnpqrstvwxyz']]], 'brand'],
        'a draft product' => ['NEW-1', ['rules' => 'DRAFT'], 'product'],
        'an inactive category' => ['NEW-1', ['rules' => 'OFF-CATEGORY'], 'category'],
        'an inactive brand' => ['NEW-1', ['rules' => 'OFF-BRAND'], 'brand'],
    ]);
});

describe('who may', function () {
    it('lets staff manage coupons in their own stores only; nobody without the job', function () {
        couponManager(['sa']);

        expect(fn () => couponCreate(storeCode: 'eg'))->toThrow(Unauthorized::class)
            ->and(fn () => app(CreateCouponHandler::class)->handle(new CreateCoupon('not-a-store', 'NEW-1', couponFields())))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['sa']));
        expect(fn () => couponCreate())->toThrow(Unauthorized::class)
            ->and(DB::table('promotions.coupons')->count())->toBe(0);
    });

    it('answers a coupon of a store the staff member does not cover as not found, as an unknown one', function (Closure $write) {
        Fx::actAsStaff(Fx::staffWith([PromotionsPermissions::COUPON_MANAGE], ['eg']));
        $egypt = couponCreate('EGYPT-10', storeCode: 'eg');
        couponManager(['sa']);

        expect(fn () => $write($egypt))->toThrow(CouponNotFound::class)
            ->and(fn () => $write(Pr::orderId()))->toThrow(CouponNotFound::class)
            ->and(fn () => $write('not-an-id'))->toThrow(CouponNotFound::class);

        Fx::actAsStaff(Fx::staffWith(['access.customer.view'], ['sa']));
        expect(fn () => $write($egypt))->toThrow(Unauthorized::class);
    })->with([
        'changing' => [fn (string $id) => app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, couponFields()))],
        'switching' => [fn (string $id) => app(SwitchCouponHandler::class)->handle(new SwitchCoupon($id, false))],
        'deleting' => [fn (string $id) => app(DeleteCouponHandler::class)->handle(new DeleteCoupon($id))],
    ]);

    it('keeps a switched-off store\'s coupons for Super Admins alone (§1.8)', function () {
        // The base store is always on (platform.md §1.6): Egypt is switched off. Its id is read first —
        // the store directory no longer names an off store by its code.
        couponManager(['*']);
        $eg = Fx::storeId('eg');
        $egypt = couponCreate(storeCode: 'eg');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        $inEgypt = static fn (string $code): string => app(CreateCouponHandler::class)->handle(new CreateCoupon($eg, $code, couponFields()));

        expect(fn () => $inEgypt('NEW-1'))->toThrow(Unauthorized::class)
            ->and(fn () => app(SwitchCouponHandler::class)->handle(new SwitchCoupon($egypt, false)))->toThrow(Unauthorized::class)
            ->and(couponCreate('NEW-1'))->toBeString();

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $inEgypt('NEW-2');
        app(SwitchCouponHandler::class)->handle(new SwitchCoupon($egypt, false));

        expect(DB::table('promotions.coupons')->count())->toBe(3)
            ->and((bool) DB::table('promotions.coupons')->where('id', $egypt)->value('active'))->toBeFalse();
    });
});

describe('changing, switching, deleting', function () {
    it('changes the terms and lists for every later use, audits only what changed, keeps the code', function () {
        couponManager();
        $doors = Px::category('Doors');
        $id = couponCreate(fields: couponFields(['rules' => [['kind' => 'INCLUDE', 'target' => 'category', 'id' => $doors]]]));

        $starts = CarbonImmutable::parse((string) DB::table('promotions.coupons')->where('id', $id)->value('starts_at'));
        $changed = couponFields(['percent' => 20, 'nameEn' => 'Summer sale', 'startsAt' => $starts]);
        app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, $changed));
        $audit = couponAudit('promotions.coupon.updated');

        expect((int) DB::table('promotions.coupons')->where('id', $id)->value('percent'))->toBe(20)
            ->and(DB::table('promotions.coupons')->where('id', $id)->value('code'))->toBe('SUMMER-15')
            ->and(DB::table('promotions.coupon_rules')->where('coupon_id', $id)->count())->toBe(0)
            ->and(array_keys($audit['changes']))->toEqualCanonicalizing(['percent', 'name_en', 'rules'])
            ->and($audit['changes']['percent'])->toBe([15, 20]);

        // The same terms again: nothing changed, nothing audited.
        app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, $changed));
        expect(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.updated')->count())->toBe(1);
    });

    it('keeps a time given in another offset as the same moment, and saving it again changes nothing', function () {
        couponManager();
        $fields = couponFields(['startsAt' => CarbonImmutable::parse('2026-11-01 12:00:00.123456+03:00'), 'endsAt' => CarbonImmutable::parse('2026-11-30 23:59:59+03:00')]);
        $id = couponCreate(fields: $fields);
        $updatedAt = DB::table('promotions.coupons')->where('id', $id)->value('updated_at');
        // An hour on (the column keeps whole seconds): a needless write would show.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

        app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, $fields));
        app(SwitchCouponHandler::class)->handle(new SwitchCoupon($id, true));
        CarbonImmutable::setTestNow();

        expect(CarbonImmutable::parse((string) DB::table('promotions.coupons')->where('id', $id)->value('starts_at'))->utc()->format('Y-m-d H:i:s.u'))->toBe('2026-11-01 09:00:00.000000')
            ->and(CarbonImmutable::parse((string) DB::table('promotions.coupons')->where('id', $id)->value('ends_at'))->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-30 20:59:59')
            ->and(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.updated')->exists())->toBeFalse()
            // Nothing written either: the row is as it was.
            ->and(DB::table('promotions.coupons')->where('id', $id)->value('updated_at'))->toBe($updatedAt);
    });

    it('checks only the list entries a change adds: an old entry since archived never blocks a rename', function () {
        couponManager();
        $ready = Px::ready()['product'];
        $id = couponCreate(fields: couponFields(['rules' => [['kind' => 'INCLUDE', 'target' => 'product', 'id' => $ready]]]));
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($ready)));
        $draft = Px::product('Hinge');

        app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, couponFields(['nameEn' => 'Renamed', 'rules' => [['kind' => 'INCLUDE', 'target' => 'product', 'id' => $ready]]])));

        expect(DB::table('promotions.coupons')->where('id', $id)->value('name_en'))->toBe('Renamed')
            ->and(Pr::thrown(fn () => app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, couponFields(['rules' => [
                ['kind' => 'INCLUDE', 'target' => 'product', 'id' => $ready],
                ['kind' => 'INCLUDE', 'target' => 'product', 'id' => $draft],
            ]]))), InvalidPromotionAttribute::class)->attribute)->toBe('product');
    });

    it('changes and deletes a stored coupon a rule of today would refuse', function () {
        couponManager();
        // Written before the rule: the public only, on wholesale lines only.
        $id = Pr::coupon('OLD-ONE', with: ['for_company' => false, 'for_retail' => false]);

        app(SwitchCouponHandler::class)->handle(new SwitchCoupon($id, false));
        app(DeleteCouponHandler::class)->handle(new DeleteCoupon($id));

        expect(DB::table('promotions.coupons')->where('id', $id)->exists())->toBeFalse();
    });

    it('refuses the total of uses below the uses made, and ends the coupon set to exactly them', function () {
        couponManager();
        $id = couponCreate();
        $sara = Fx::customer();
        Pr::used($id, $sara);
        Pr::used($id, Fx::customer('omar@example.test'));

        expect(fn () => app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, couponFields(['maxUsesTotal' => 1]))))->toThrow(CouponInUse::class);

        app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, couponFields(['maxUsesTotal' => 2])));

        expect(couponRefusalNow($sara, 'SUMMER-15'))->toBe(CouponRefusal::UsedUp);
    });

    it('switches a coupon off and on again, audited once each way', function () {
        couponManager();
        $id = couponCreate();
        $sara = Fx::customer();
        couponManager();

        app(SwitchCouponHandler::class)->handle(new SwitchCoupon($id, false));
        app(SwitchCouponHandler::class)->handle(new SwitchCoupon($id, false));

        expect(couponRefusalNow($sara, 'SUMMER-15'))->toBe(CouponRefusal::Inactive)
            ->and(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.deactivated')->count())->toBe(1)
            ->and(couponAudit('promotions.coupon.deactivated')['changes'])->toBe(['active' => [true, false]]);

        app(SwitchCouponHandler::class)->handle(new SwitchCoupon($id, true));

        expect(couponRefusalNow($sara, 'SUMMER-15'))->toBeNull()
            ->and(couponAudit('promotions.coupon.activated')['changes'])->toBe(['active' => [false, true]]);
    });

    it('deletes a coupon never used, its lists and assignments with it, audited', function () {
        couponManager();
        $id = couponCreate(fields: couponFields(['eligibility' => 'ASSIGNED', 'rules' => [['kind' => 'INCLUDE', 'target' => 'category', 'id' => Px::category('Doors')]]]));
        Pr::assign($id, Fx::customer());
        couponManager();

        app(DeleteCouponHandler::class)->handle(new DeleteCoupon($id));

        expect(DB::table('promotions.coupons')->where('id', $id)->exists())->toBeFalse()
            ->and(DB::table('promotions.coupon_rules')->where('coupon_id', $id)->exists())->toBeFalse()
            ->and(DB::table('promotions.coupon_assignments')->where('coupon_id', $id)->exists())->toBeFalse()
            ->and(couponAudit('promotions.coupon.deleted')['changes']['code'])->toBe(['SUMMER-15', null]);
    });

    it('never deletes a coupon used once: switched off instead', function () {
        couponManager();
        $id = couponCreate();
        Pr::used($id, Fx::customer());
        couponManager();

        expect(fn () => app(DeleteCouponHandler::class)->handle(new DeleteCoupon($id)))->toThrow(CouponInUse::class)
            ->and(DB::table('promotions.coupons')->where('id', $id)->exists())->toBeTrue()
            ->and(DB::table('platform.audit_entries')->where('action', 'promotions.coupon.deleted')->exists())->toBeFalse();
    });
});

describe('a list entry removed while the coupon is saved', function () {
    it('answers it as the check would have, never as a database error', function (Closure $save) {
        couponManager();
        $id = couponCreate();
        // Catalog said the category was there and on; it is gone by the time the rule is written.
        $gone = strtolower((string) Str::ulid());
        $catalog = Mockery::mock(CatalogApi::class);
        $catalog->shouldReceive('category')->andReturn(new CategoryDto($gone, null, 'أبواب', 'Doors', true));
        app()->instance(CatalogApi::class, $catalog);
        $fields = couponFields(['rules' => [['kind' => 'INCLUDE', 'target' => 'category', 'id' => $gone]]]);

        expect(Pr::thrown(fn () => $save($id, $fields), InvalidPromotionAttribute::class)->attribute)->toBe('rules')
            ->and(DB::table('promotions.coupon_rules')->where('category_id', $gone)->exists())->toBeFalse();
    })->with([
        'creating' => [fn (string $id, CouponFields $fields) => couponCreate('OTHER-1', $fields)],
        'changing' => [fn (string $id, CouponFields $fields) => app(ChangeCouponHandler::class)->handle(new ChangeCoupon($id, $fields))],
    ]);
});
