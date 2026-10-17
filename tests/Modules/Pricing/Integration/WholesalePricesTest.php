<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\BandsInvalid;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Exception\NotSoldWholesale;
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
|--------------------------------------------------------------------------
| Wholesale prices (pricing.md §1.4, §3; step 5): a size's bands, all at once - the first at the
| product's wholesale minimum and below the retail price, each next higher and cheaper - for a size
| the store sells wholesale; a wholesale line pays the band with the highest start at or below its
| quantity, a retail line never.
|--------------------------------------------------------------------------
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
 * A size the store sells retail and wholesale, wholesale from 20 pieces, its retail price 100.
 */
function wholesaleSize(bool $wholesale = true): string
{
    $ready = Px::ready();
    [$size] = $ready['variants'];
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));
    Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Rx::storeId('sa'), $ready['product'], [$size => ['retail' => true, 'wholesale' => $wholesale]], 1, null, $wholesale ? 20 : null, null)));

    return $size;
}

/**
 * @param  array<int|string, string>  $bands
 */
function wholesaleSet(string $size, array $bands): void
{
    Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Rx::storeId('sa'), $size, $bands)));
}

function wholesaleUnit(string $size, SaleMode $mode, int $quantity): ?int
{
    return app(PricingApi::class)->prices(StoreId::fromString(Rx::storeId('sa')), [new CartLineDto($size, $mode, $quantity)])->lines[0]->unit->minorUnits ?? null;
}

it('prices a wholesale line by the band with the highest start at or below its quantity, a retail line never', function () {
    $size = wholesaleSize();
    Rx::set('sa', [$size => '100']);

    wholesaleSet($size, [100 => '78', 20 => '85']);

    expect(wholesaleUnit($size, SaleMode::Wholesale, 20))->toBe(8500)
        ->and(wholesaleUnit($size, SaleMode::Wholesale, 99))->toBe(8500)
        ->and(wholesaleUnit($size, SaleMode::Wholesale, 150))->toBe(7800)
        ->and(wholesaleUnit($size, SaleMode::Retail, 150))->toBe(10000)
        ->and(Rx::auditChanges('pricing.wholesale_prices.set', $size))->toBe(['bands' => [null, '20 = 85.00; 100 = 78.00']]);

    // None removes them: a wholesale line pays the retail price again.
    wholesaleSet($size, []);

    expect(wholesaleUnit($size, SaleMode::Wholesale, 150))->toBe(10000);
});

it('refuses bands that do not start at the minimum, are not cheaper as they go, or start at the retail price', function () {
    $size = wholesaleSize();
    Rx::set('sa', [$size => '100']);

    expect(fn () => wholesaleSet($size, [10 => '85']))->toThrow(BandsInvalid::class)
        ->and(fn () => wholesaleSet($size, [20 => '85', 100 => '85']))->toThrow(BandsInvalid::class)
        ->and(fn () => wholesaleSet($size, [20 => '100']))->toThrow(BandsInvalid::class)
        ->and(Rx::invalid(fn () => wholesaleSet($size, [0 => '85']))?->attribute)->toBe('quantity')
        // Above what the column keeps: refused as a quantity, never a database error.
        ->and(Rx::invalid(fn () => wholesaleSet($size, [20 => '85', 3000000000 => '70']))?->attribute)->toBe('quantity');

    wholesaleSet($size, [20 => '99.99']);

    expect(wholesaleUnit($size, SaleMode::Wholesale, 20))->toBe(9999);
});

it('needs the size sold wholesale there and a retail price, and Edit Wholesale Prices in that store', function () {
    $retailOnly = wholesaleSize(wholesale: false);
    $priceless = wholesaleSize();
    Rx::set('sa', [$retailOnly => '100']);

    expect(fn () => wholesaleSet($retailOnly, [1 => '85']))->toThrow(NotSoldWholesale::class)
        ->and(fn () => wholesaleSet($priceless, [20 => '85']))->toThrow(NoRetailPrice::class);

    Cx::actAsStaffWith([PricingPermissions::WHOLESALE_EDIT], ['eg']);

    expect(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Rx::storeId('sa'), $priceless, [])))->toThrow(Unauthorized::class);
});
