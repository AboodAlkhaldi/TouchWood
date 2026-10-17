<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\CouponResult;
use Modules\Promotions\Public\Enums\Audience;
use Modules\Promotions\Public\Enums\CouponRefusal;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| `applyCoupon` and `customerDiscountRoom` as Sales calls them (promotions.md §1.2, §1.3, §1.6, §2.1),
| over real Catalog rows: the code however typed, the lines a coupon covers through Catalog's tree,
| every refusal a quote can give, the room under Pricing's ceiling — and nothing of another store.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  list<LinePriceDto>  $lines
 */
function tryCoupon(string $code, string $customerId, array $lines, Audience $audience = Audience::Public, string $storeCode = 'sa'): CouponResult
{
    return Pr::api()->applyCoupon(new CouponRequest(Fx::storeId($storeCode), $code, $customerId, $audience, Pr::prices($lines, $storeCode)));
}

/** @return list<int> */
function shareMinors(CouponResult $result): array
{
    return array_map(static fn (Money $share): int => $share->minorUnits, $result->shares);
}

describe('what it takes off', function () {
    it('answers the discount, its share of each line in the cart\'s order, the names and whether points may follow', function () {
        $customer = Fx::customer();
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        $coupon = Pr::coupon(with: ['allow_with_points' => false]);

        $result = tryCoupon('SUMMER-15', $customer, [Pr::line($a, 200_00), Pr::line($b, 50_00, quantity: 2)]);

        expect($result->isTaken())->toBeTrue()
            ->and($result->couponId)->toBe($coupon)
            ->and($result->nameEn)->toBe('Summer coupon')
            ->and($result->nameAr)->toBe('كوبون الصيف')
            ->and($result->discount?->minorUnits)->toBe(45_00)
            ->and($result->discount?->currencyCode)->toBe(Pr::currency())
            ->and(shareMinors($result))->toBe([30_00, 15_00])
            ->and($result->allowWithPoints)->toBeFalse()
            ->and($result->refusal)->toBeNull();
    });

    it('finds the coupon typed in lower case, with Arabic digits and spaces around it', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon('EID-2026');

        expect(tryCoupon('  eid-٢٠٢٦ ', $customer, [Pr::line($a, 100_00)])->discount?->minorUnits)->toBe(15_00)
            ->and(tryCoupon('eid-۲۰۲۶', $customer, [Pr::line($a, 100_00)])->discount?->minorUnits)->toBe(15_00);
    });

    it('covers a category\'s sub-categories, skips an excluded brand inside it, and leaves the rest of a mixed cart', function () {
        $customer = Fx::customer();
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        [$inKitchens] = Px::ready(['60 cm'], $drawers)['variants'];
        $excluded = Px::ready(['60 cm'], $drawers);
        [$elsewhere] = Px::ready(['60 cm'], Px::category('Lighting'))['variants'];
        $brand = (string) DB::table('catalog.products')->where('id', $excluded['product'])->value('brand_id');
        Pr::coupon(rules: [['INCLUDE', 'category', $kitchens], ['EXCLUDE', 'brand', $brand]]);

        $result = tryCoupon('SUMMER-15', $customer, [Pr::line($inKitchens, 100_00), Pr::line($excluded['variants'][0], 100_00), Pr::line($elsewhere, 100_00)]);

        expect(shareMinors($result))->toBe([15_00, 0, 0]);
    });

    it('skips an excluded sub-category inside an included one: "all of Kitchens except Drawers"', function () {
        $customer = Fx::customer();
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        [$inDrawers] = Px::ready(['60 cm'], Px::category('Soft close', $drawers))['variants'];
        [$inCabinets] = Px::ready(['60 cm'], Px::category('Cabinets', $kitchens))['variants'];
        Pr::coupon(rules: [['INCLUDE', 'category', $kitchens], ['EXCLUDE', 'category', $drawers]]);

        expect(shareMinors(tryCoupon('SUMMER-15', $customer, [Pr::line($inDrawers, 100_00), Pr::line($inCabinets, 100_00)])))->toBe([0, 15_00]);
    });

    // Pricing gives a wholesale line its band as `listUnit` (pricing.md §1.6, owner 2026-10-10), so a
    // line at its band price comes here with `unit` equal to `listUnit` — what this cart builds.
    it('skips a reduced line unless the coupon allows it, and takes a line at its normal price as not reduced', function () {
        $customer = Fx::customer('co@example.test', 'sa', 'company');
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Pr::coupon();
        Pr::coupon('REDUCED-OK', with: ['on_reduced_lines' => true]);
        $lines = [Pr::line($a, 80_00, listUnitMinor: 100_00), Pr::line($b, 70_00, mode: SaleMode::Wholesale)];

        expect(shareMinors(tryCoupon('SUMMER-15', $customer, $lines, Audience::Company)))->toBe([0, 10_50])
            ->and(shareMinors(tryCoupon('REDUCED-OK', $customer, $lines, Audience::Company)))->toBe([12_00, 10_50]);
    });
});

describe('refused whole', function () {
    it('refuses a code no coupon of the store carries, or one that cannot be a code, naming no coupon', function (string $code) {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon();
        Pr::coupon('OTHER-STORE', 'eg', with: ['currency_code' => Pr::currency('eg')]);

        $result = tryCoupon($code, $customer, [Pr::line($a, 100_00)]);

        expect($result->refusal)->toBe(CouponRefusal::UnknownCode)
            ->and($result->couponId)->toBeNull()
            ->and($result->discount)->toBeNull()
            ->and($result->shares)->toBe([]);
    })->with(['NOPE', 'OTHER-STORE', 'ab', 'SUMMER 15', '', str_repeat('A', 31)]);

    it('refuses with the coupon\'s reason, naming the coupon', function (array $with, CouponRefusal $refusal) {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon(with: $with);

        $result = tryCoupon('SUMMER-15', $customer, [Pr::line($a, 100_00)]);

        expect($result->refusal)->toBe($refusal)
            ->and($result->isTaken())->toBeFalse()
            ->and($result->couponId)->toBe($coupon)
            ->and($result->nameEn)->toBe('Summer coupon')
            ->and($result->discount)->toBeNull();
    })->with([
        'inactive' => [['active' => false], CouponRefusal::Inactive],
        'not started' => [['starts_at' => now()->addDay()], CouponRefusal::NotStarted],
        'ended' => [['starts_at' => now()->subDays(3), 'ends_at' => now()->subDay()], CouponRefusal::Ended],
        'assigned to others' => [['eligibility' => 'ASSIGNED'], CouponRefusal::NotAssignedToYou],
        'under the minimum' => [['minimum_minor' => 100_01], CouponRefusal::BelowMinimum],
        'nothing eligible' => [['for_retail' => false], CouponRefusal::NothingEligible],
    ]);

    it('refuses one used up for everyone, and one used up by this customer though others may still use it', function () {
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        [$a] = Px::ready()['variants'];
        $everyone = Pr::coupon('ONCE-ONLY', with: ['max_uses_total' => 1]);
        Pr::used($everyone, $omar);
        $each = Pr::coupon('TWICE-EACH', with: ['max_uses_per_customer' => 2]);
        Pr::used($each, $sara);
        Pr::used($each, $sara);

        expect(tryCoupon('ONCE-ONLY', $sara, [Pr::line($a, 100_00)])->refusal)->toBe(CouponRefusal::UsedUp)
            ->and(tryCoupon('TWICE-EACH', $sara, [Pr::line($a, 100_00)])->refusal)->toBe(CouponRefusal::UsedUpForYou)
            ->and(tryCoupon('TWICE-EACH', $omar, [Pr::line($a, 100_00)])->discount?->minorUnits)->toBe(15_00);
    });

    it('takes an assigned coupon from the customer it was assigned to only', function () {
        $sara = Fx::customer();
        $omar = Fx::customer('omar@example.test');
        [$a] = Px::ready()['variants'];
        $coupon = Pr::coupon(with: ['eligibility' => 'ASSIGNED']);
        Pr::assign($coupon, $sara);

        expect(tryCoupon('SUMMER-15', $sara, [Pr::line($a, 100_00)])->discount?->minorUnits)->toBe(15_00)
            ->and(tryCoupon('SUMMER-15', $omar, [Pr::line($a, 100_00)])->refusal)->toBe(CouponRefusal::NotAssignedToYou);
    });

    it('refuses a coupon worth nothing: 1% of 49 halalas', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon(with: ['percent' => 1]);

        expect(tryCoupon('SUMMER-15', $customer, [Pr::line($a, 49)])->refusal)->toBe(CouponRefusal::WorthNothing);
    });
});

describe('the ceiling (Pricing\'s setting, §1.6)', function () {
    it('is 30% when a store opens: prices at 20% off leave 10%, at 35% off none', function () {
        $sa = StoreId::fromString(Fx::storeId('sa'));

        expect(Pr::api()->customerDiscountRoom($sa, Pr::money(1_000_00), Pr::money(800_00))->minorUnits)->toBe(100_00)
            ->and(Pr::api()->customerDiscountRoom($sa, Pr::money(1_000_00), Pr::money(650_00))->minorUnits)->toBe(0)
            ->and(Pr::api()->customerDiscountRoom($sa, Pr::money(1_000_00), Pr::money(1_000_00))->minorUnits)->toBe(300_00);
    });

    it('reads the store\'s own ceiling, as an admin changed it', function () {
        Pr::ceiling(10);

        expect(Pr::api()->customerDiscountRoom(StoreId::fromString(Fx::storeId('sa')), Pr::money(1_000_00), Pr::money(1_000_00))->minorUnits)->toBe(100_00)
            ->and(Pr::api()->customerDiscountRoom(StoreId::fromString(Fx::storeId('eg')), Pr::money(1_000_00, 'eg'), Pr::money(1_000_00, 'eg'))->minorUnits)->toBe(300_00);
    });

    it('refuses a coupon that fits alone but not with the prices\' own reductions, never trimming it', function () {
        $customer = Fx::customer();
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Pr::coupon(with: ['on_reduced_lines' => true]);

        // Gross 2,000, net 1,600 (20% off the first line): 10% room = 200; 15% of 1,600 = 240.
        $reduced = [Pr::line($a, 600_00, listUnitMinor: 1_000_00), Pr::line($b, 1_000_00)];
        // Full price: 30% room = 600; 15% of 2,000 = 300.
        $full = [Pr::line($a, 1_000_00), Pr::line($b, 1_000_00)];

        expect(tryCoupon('SUMMER-15', $customer, $reduced)->refusal)->toBe(CouponRefusal::PassesCeiling)
            ->and(tryCoupon('SUMMER-15', $customer, $full)->discount?->minorUnits)->toBe(300_00);
    });

    it('lets a 70%-off clearance line sell under a 30% ceiling, with no coupon on top', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon(with: ['on_reduced_lines' => true]);
        $prices = Pr::prices([Pr::line($a, 300_00, listUnitMinor: 1_000_00)]);

        expect(Pr::api()->customerDiscountRoom(StoreId::fromString(Fx::storeId('sa')), $prices->grossSubtotal, $prices->netSubtotal)->minorUnits)->toBe(0)
            ->and(tryCoupon('SUMMER-15', $customer, [Pr::line($a, 300_00, listUnitMinor: 1_000_00)])->refusal)->toBe(CouponRefusal::PassesCeiling);
    });
});

describe('what Sales passes in', function () {
    it('refuses prices of another store, prices in another currency, and ids that are not ids', function () {
        $customer = Fx::customer();
        [$a] = Px::ready()['variants'];
        Pr::coupon();
        $sa = Pr::prices([Pr::line($a, 100_00)]);
        $eg = Pr::prices([Pr::line($a, 100_00, storeCode: 'eg')], 'eg');

        expect(fn () => Pr::api()->applyCoupon(new CouponRequest(Fx::storeId('sa'), 'SUMMER-15', $customer, Audience::Public, $eg)))->toThrow(InvalidPromotionAttribute::class)
            ->and(fn () => Pr::api()->applyCoupon(new CouponRequest('not-a-store', 'SUMMER-15', $customer, Audience::Public, $sa)))->toThrow(InvalidPromotionAttribute::class)
            ->and(fn () => Pr::api()->applyCoupon(new CouponRequest(Fx::storeId('sa'), 'SUMMER-15', 'nobody', Audience::Public, $sa)))->toThrow(InvalidPromotionAttribute::class);

        $wrongCurrency = new CartPricesDto(Fx::storeId('sa'), Pr::currency('eg'), 1500, [Pr::line($a, 100_00, storeCode: 'eg')], [], Pr::money(100_00, 'eg'), Pr::money(100_00, 'eg'), now()->toImmutable(), null);

        // Another store's prices are refused before any code is looked up — even one that names no coupon.
        expect(fn () => Pr::api()->applyCoupon(new CouponRequest(Fx::storeId('sa'), 'NO-SUCH-CODE', $customer, Audience::Public, $eg)))->toThrow(InvalidPromotionAttribute::class);

        $refused = null;

        try {
            Pr::api()->applyCoupon(new CouponRequest(Fx::storeId('sa'), 'SUMMER-15', $customer, Audience::Public, $wrongCurrency));
        } catch (InvalidPromotionAttribute $error) {
            $refused = $error;
        }

        // The store's prices are the right store's, so only the currency is refused.
        expect($refused?->attribute)->toBe('currency');
    });

    it('works a quote out in a fixed number of queries however many lines the cart has', function () {
        $customer = Fx::customer();
        $few = Px::ready(['60 cm'])['variants'];
        $many = Px::ready(['60 cm', '70 cm', '80 cm', '90 cm', '100 cm'])['variants'];
        Pr::coupon();
        tryCoupon('SUMMER-15', $customer, [Pr::line($few[0], 100_00)]);

        $queries = Cx::recordQueries();
        tryCoupon('SUMMER-15', $customer, [Pr::line($few[0], 100_00)]);
        $one = count($queries);
        $queries = Cx::recordQueries();
        tryCoupon('SUMMER-15', $customer, array_map(static fn (string $variant): LinePriceDto => Pr::line($variant, 100_00), [...$few, ...$many]));

        expect(count($queries))->toBe($one)->and($one)->toBeLessThanOrEqual(6);
    });
});
