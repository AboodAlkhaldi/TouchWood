<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSale;
use Modules\Pricing\Application\Command\ChangeSale\ChangeSaleHandler;
use Modules\Pricing\Application\Command\EndSale\EndSale;
use Modules\Pricing\Application\Command\EndSale\EndSaleHandler;
use Modules\Pricing\Application\Command\RemoveSale\RemoveSale;
use Modules\Pricing\Application\Command\RemoveSale\RemoveSaleHandler;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Domain\Exception\AlwaysWinsOverlap;
use Modules\Pricing\Domain\Exception\CeilingBelowSales;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Exception\NotChangeable;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\SaleNotBelowRetail;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| Sales (pricing.md §1.3, §1.12, §3, §4.1; §8 #9) and the store's discount ceiling: a price or a
| percentage off, for one size, some or every size of a product; refused whole, naming the sizes, when
| one cannot take it; never deeper than the ceiling - a lower ceiling and a retail price taking a sale
| past it held back with a note (owner, 2026-10-11/13); changed, ended and removed by their state.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-13 10:00:00+00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  list<string>  $variantIds
 */
function salesAdd(array $variantIds, ?string $percent = null, ?string $price = null, ?string $startsAt = null, ?string $endsAt = null, bool $alwaysWins = false, ?string $productId = null): string
{
    Rx::switchOn('sa', $variantIds, $variantIds === [] ? $productId : null);

    return Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), $productId, $variantIds, $percent, $price, $startsAt, $endsAt, $alwaysWins)));
}

function salesUnit(string $variantId): ?int
{
    $prices = app(PricingApi::class)->prices(StoreId::fromString(Rx::storeId('sa')), [new CartLineDto($variantId, SaleMode::Retail, 1)]);

    return $prices->lines[0]->unit->minorUnits ?? null;
}

function salesCeiling(int $percent): void
{
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(UpdateSettingHandler::class)->handle(new UpdateSetting(PricingSettings::MAX_DISCOUNT_PERCENT, 'sa', $percent));
}

describe('adding a sale', function () {
    it('takes a percentage or a price, priced from now, a percentage half up on the retail price', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Rx::set('sa', [$a => '99.99', $b => '100']);

        salesAdd([$a], percent: '12.5');
        salesAdd([$b], price: '80');

        // 99.99 less 12.5% = 87.49125, half up to the coin: 87.49.
        expect(salesUnit($a))->toBe(8749)
            ->and(salesUnit($b))->toBe(8000)
            ->and(Fx::audits('pricing.sale.added'))->toBe(2);
    });

    it('covers every size of a product when only the product is given, one sale over them', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        Rx::set('sa', array_fill_keys($ready['variants'], '100'));

        $sale = salesAdd([], percent: '10', productId: $ready['product']);

        expect(DB::table('pricing.sale_variants')->where('sale_id', $sale)->count())->toBe(2)
            ->and(salesUnit($ready['variants'][1]))->toBe(9000);
    });

    it('refuses the whole sale, naming the sizes that cannot take it (owner, 2026-10-11)', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$a, $b] = $ready['variants'];
        Rx::set('sa', [$a => '100']);

        try {
            salesAdd([$a, $b], percent: '10');
            $named = null;
        } catch (NoRetailPrice $error) {
            $named = $error->variants;
        }

        // "All Sizes" (owner Q39): every size switched on with a price - the one with none is not refused,
        // it is left out.
        $all = salesAdd([], percent: '10', productId: $ready['product']);

        expect(DB::table('pricing.sale_variants')->where('sale_id', $all)->pluck('variant_id')->all())->toBe([$a]);

        DB::table('pricing.sales')->delete();
        Rx::set('sa', [$b => '50']);

        try {
            salesAdd([], price: '60', productId: $ready['product']);
            $notBelow = null;
        } catch (SaleNotBelowRetail $error) {
            $notBelow = $error->variants;
        }

        Rx::set('sa', [$a => '0.01', $b => '0.02']);
        salesCeiling(100);

        try {
            salesAdd([], percent: '99.99', productId: $ready['product']);
            $zero = null;
        } catch (PriceNotPositive $error) {
            $zero = $error->variants;
        }

        expect($named)->toBe([$b])
            ->and($notBelow)->toBe([$b])
            ->and($zero)->toBe([$a, $b])
            ->and(DB::table('pricing.sales')->count())->toBe(0);
    });

    it('takes some of a product\'s sizes too (owner, 2026-10-13), and nothing of an archived product', function () {
        $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
        Rx::set('sa', array_fill_keys($ready['variants'], '100'));

        $some = salesAdd([$ready['variants'][0], $ready['variants'][1]], percent: '10');

        expect(DB::table('pricing.sale_variants')->where('sale_id', $some)->count())->toBe(2)
            ->and(salesUnit($ready['variants'][2]))->toBe(10000);

        DB::table('pricing.sales')->delete();
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($ready['product'])));

        expect(Rx::invalid(fn () => salesAdd([], percent: '10', productId: $ready['product']))?->attribute)->toBe('product')
            ->and(Rx::invalid(fn () => salesAdd([$ready['variants'][0]], percent: '10'))?->attribute)->toBe('variant')
            ->and(DB::table('pricing.sales')->count())->toBe(0);
    });

    it('refuses a sale deeper than the store\'s ceiling, 30% unless an admin sets another', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);

        expect(fn () => salesAdd([$size], percent: '30.01'))->toThrow(OverCeiling::class)
            ->and(fn () => salesAdd([$size], price: '69.99'))->toThrow(OverCeiling::class);

        salesAdd([$size], price: '70');

        expect(salesUnit($size))->toBe(7000);
    });

    it('refuses an end not after the start, a percentage leaving 0, and two "always wins" on a size in the same dates', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '0.01']);
        salesCeiling(100);

        expect(fn () => salesAdd([$size], percent: '99.99'))->toThrow(PriceNotPositive::class)
            ->and(fn () => salesAdd([$size], percent: '10', startsAt: '2026-10-20 00:00:00+00', endsAt: '2026-10-19 00:00:00+00'))->toThrow(WindowInvalid::class);

        Rx::set('sa', [$size => '100']);
        salesAdd([$size], percent: '10', endsAt: '2026-10-30 00:00:00+00', alwaysWins: true);

        expect(fn () => salesAdd([$size], percent: '5', startsAt: '2026-10-29 00:00:00+00', alwaysWins: true))->toThrow(AlwaysWinsOverlap::class);

        salesAdd([$size], percent: '5', startsAt: '2026-10-30 00:00:00+00', alwaysWins: true);

        expect(DB::table('pricing.sales')->count())->toBe(2);
    });

    it('refuses sizes of two products in one sale, and needs Edit Prices and Sales in that store', function () {
        $first = Px::ready()['variants'][0];
        $second = Px::ready()['variants'][0];
        Rx::set('sa', [$first => '100', $second => '100']);

        expect(Rx::invalid(fn () => salesAdd([$first, $second], percent: '10'))?->attribute)->toBe('variant');

        Cx::actAsStaffWith([PricingPermissions::PRICE_EDIT], ['eg']);

        expect(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$first], '10', null, null, null)))->toThrow(Unauthorized::class);
    });
});

describe('a sale\'s life (§4.1)', function () {
    it('changes a scheduled sale in full, a running one only its end, an ended one never', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        $scheduled = salesAdd([$size], percent: '10', startsAt: '2026-10-20 00:00:00+00');
        $running = salesAdd([$size], percent: '5');
        $handler = app(ChangeSaleHandler::class);

        Fx::asSystem(fn () => $handler->handle(new ChangeSale($scheduled, null, '85', '2026-10-21 00:00:00+00', null)));
        Fx::asSystem(fn () => $handler->handle(new ChangeSale($running, '5', null, null, '2026-10-25 00:00:00+00')));

        expect(DB::table('pricing.sales')->where('id', $scheduled)->value('amount_minor'))->toBe(8500)
            ->and((string) DB::table('pricing.sales')->where('id', $running)->value('ends_at'))->toStartWith('2026-10-25')
            ->and(fn () => Fx::asSystem(fn () => $handler->handle(new ChangeSale($running, '6', null, null, null))))->toThrow(NotChangeable::class);

        CarbonImmutable::setTestNow('2026-10-26 00:00:00+00');

        expect(fn () => Fx::asSystem(fn () => $handler->handle(new ChangeSale($running, '5', null, null, null))))->toThrow(NotChangeable::class);
    });

    it('gives a running sale a new end though the retail price has since dropped below its price (§1.3)', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        $running = salesAdd([$size], price: '75');
        Rx::set('sa', [$size => '60']);

        Fx::asSystem(fn () => app(ChangeSaleHandler::class)->handle(new ChangeSale($running, null, '75', null, '2026-10-20 00:00:00+00')));

        expect((string) DB::table('pricing.sales')->where('id', $running)->value('ends_at'))->toStartWith('2026-10-20')
            ->and(salesUnit($size))->toBe(6000);
    });

    it('ends a running sale now and removes a scheduled one, each refused in the other state', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        $running = salesAdd([$size], percent: '10');
        $scheduled = salesAdd([$size], percent: '20', startsAt: '2026-10-20 00:00:00+00');

        expect(fn () => Fx::asSystem(fn () => app(RemoveSaleHandler::class)->handle(new RemoveSale($running))))->toThrow(NotChangeable::class)
            ->and(fn () => Fx::asSystem(fn () => app(EndSaleHandler::class)->handle(new EndSale($scheduled))))->toThrow(NotChangeable::class);

        CarbonImmutable::setTestNow('2026-10-13 11:00:00+00');
        Fx::asSystem(fn () => app(EndSaleHandler::class)->handle(new EndSale($running)));
        Fx::asSystem(fn () => app(RemoveSaleHandler::class)->handle(new RemoveSale($scheduled)));

        expect(salesUnit($size))->toBe(10000)
            ->and(DB::table('pricing.sales')->count())->toBe(1)
            ->and(Fx::audits('pricing.sale.ended', $running))->toBe(1)
            ->and(Fx::audits('pricing.sale.removed', $scheduled))->toBe(1);
    });
});

describe('the ceiling held (owner, 2026-10-11/13)', function () {
    it('holds back a lower ceiling while sales go deeper, naming them and their products, and takes a higher one', function () {
        $ready = Px::ready();
        $size = $ready['variants'][0];
        Rx::set('sa', [$size => '100']);
        $sale = salesAdd([$size], percent: '25');
        app()->setLocale('en');

        try {
            salesCeiling(20);
            $named = $products = null;
        } catch (CeilingBelowSales $error) {
            [$named, $products] = [$error->sales, $error->products];
        }

        expect($named)->toBe([$sale])
            ->and($products)->toBe(app(CatalogApi::class)->product($ready['product'])?->nameEn)
            ->and(DB::table('platform.settings')->where('key', PricingSettings::MAX_DISCOUNT_PERCENT)->exists())->toBeFalse();

        salesCeiling(25);
        salesCeiling(40);

        expect(DB::table('platform.settings')->where('key', PricingSettings::MAX_DISCOUNT_PERCENT)->value('value'))->toBe('40');
    });

    it('reads the sales under the store\'s pricing lock, so one saved meanwhile is seen', function () {
        Px::ready();
        $queries = Cx::recordQueries();

        salesCeiling(20);

        $locks = array_values(array_filter($queries->getArrayCopy(), static fn (array $query): bool => str_contains($query['sql'], 'pg_advisory_xact_lock')));

        expect(array_column($locks, 'bindings'))->toContain(['pricing:store:'.Rx::storeId('sa')]);
    });

    it('holds back a retail price that would take a fixed-price sale past the ceiling, naming the product', function () {
        $ready = Px::ready();
        $size = $ready['variants'][0];
        Rx::set('sa', [$size => '100']);
        salesAdd([$size], price: '75');

        try {
            Rx::set('sa', [$size => '200']);
            $products = null;
        } catch (OverCeiling $error) {
            $products = $error->products;
        }

        expect($products)->toBe(app(CatalogApi::class)->product($ready['product'])?->nameAr)
            ->and(Rx::retail('sa', $size)?->amount_minor)->toBe(10000);

        Rx::set('sa', [$size => '105']);

        expect(Rx::retail('sa', $size)?->amount_minor)->toBe(10500);
    });
});
