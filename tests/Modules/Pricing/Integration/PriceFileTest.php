<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\SetPriceFromFile\SetPriceFromFile;
use Modules\Pricing\Application\Command\SetPriceFromFile\SetPriceFromFileHandler;
use Modules\Pricing\Application\Prices\StoreWiring;
use Modules\Pricing\Infrastructure\Import\PriceImportSection;
use Modules\Pricing\Infrastructure\Queue\SetPriceFromFileJob;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| The store's file (pricing.md §1.8): the page says the retail price each item sets, or why not; an item
| switched on sets it once Catalog's change commits, as the file's, the ceiling held as for staff; a
| wired store's prices are the provider's.
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

function priceFileStore(): StoreId
{
    return StoreId::fromString(Rx::storeId('sa'));
}

function priceFileWired(): void
{
    app()->instance(StoreWiring::class, new class implements StoreWiring
    {
        public function isWired(StoreId $store): bool
        {
            return true;
        }
    });
}

it('says the retail price each item will set, or why not', function () {
    app()->setLocale('en');
    $section = app(PriceImportSection::class);
    $currency = app(PlatformApi::class)->store(priceFileStore())?->currencyCode;

    expect($section->lines(priceFileStore(), null, 5))->toBe([])
        ->and($section->lines(priceFileStore(), '120.5', 5))->toBe(["The retail price will be set to 120.5 {$currency}."])
        ->and($section->lines(priceFileStore(), '1.005', 5)[0] ?? '')->toStartWith('The price 1.005 cannot be set: the store\'s currency has')
        ->and($section->lines(priceFileStore(), '0', 5))->toBe(['The price 0 cannot be set: it must be above 0.'])
        ->and($section->lines(priceFileStore(), 'abc', 5))->toBe(['The price abc cannot be set: it is not a number.']);

    priceFileWired();

    expect(app(PriceImportSection::class)->lines(priceFileStore(), '120.5', 5))->toBe(['The price is ignored: this store takes its prices from its provider.']);
});

it('sets the price once Catalog\'s change commits, as the file\'s, for whoever switched it on', function () {
    [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
    Rx::set('sa', [$a => '100']);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $insideTheChange = null;

    DB::transaction(function () use ($a, $b, &$insideTheChange): void {
        app(PriceImportSection::class)->accepted(priceFileStore(), [$a, $b], '120.5', null);
        $insideTheChange = Rx::retail('sa', $a)?->amount_minor;
    });

    expect($insideTheChange)->toBe(10000)
        ->and(Rx::retail('sa', $a)?->amount_minor)->toBe(12050)
        ->and(Rx::retail('sa', $a)?->source)->toBe('FILE')
        ->and(Rx::retail('sa', $b)?->amount_minor)->toBe(12050)
        ->and(Rx::auditChanges('pricing.retail_price.filed', $a))->toBe(['amount' => ['100.00', '120.50']]);
});

it('sets an off store\'s price for the admin who filled it, though they may not switch stores', function () {
    [$size] = Px::ready()['variants'];
    Rx::set('eg', [$size => '100']);
    // Read while it is on: the fixtures look up stores that are.
    $eg = Rx::storeId('eg');
    $admin = Fx::staffWith([CatalogPermissions::LISTING_FILL], ['eg'], RoleLevel::Admin);
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

    // Queued by the admin, run by the queue as the system on their behalf.
    Fx::actAsStaff($admin);
    SetPriceFromFileJob::dispatch($eg, [$size], '120');

    expect((int) DB::table('pricing.retail_prices')->where('store_id', $eg)->where('variant_id', $size)->value('amount_minor'))->toBe(12000);
});

it('holds a price back as for staff when it would take a sale past the ceiling', function () {
    [$size] = Px::ready()['variants'];
    Rx::set('sa', [$size => '100']);
    Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, [$size], null, '75', null, null, false)));

    // 200 would make the 75 sale 62.5% off, past the 30% ceiling: the job goes to the failed jobs screen.
    Fx::asSystem(fn () => app(PriceImportSection::class)->accepted(priceFileStore(), [$size], '200', null));

    expect(Rx::retail('sa', $size)?->amount_minor)->toBe(10000)
        ->and(Rx::retail('sa', $size)?->source)->toBe('STAFF');
});

it('queues nothing for a wired store, an item with no price or a price it cannot keep, and the job leaves a wired store alone', function () {
    [$size] = Px::ready()['variants'];
    Rx::set('sa', [$size => '100']);
    Queue::fake();

    Fx::asSystem(fn () => app(PriceImportSection::class)->accepted(priceFileStore(), [$size], null, 5));
    Fx::asSystem(fn () => app(PriceImportSection::class)->accepted(priceFileStore(), [$size], '0', 5));
    priceFileWired();
    Fx::asSystem(fn () => app(PriceImportSection::class)->accepted(priceFileStore(), [$size], '120', 5));
    Queue::assertNothingPushed();

    Fx::asSystem(fn () => app(SetPriceFromFileHandler::class)->handle(new SetPriceFromFile(Rx::storeId('sa'), [$size], '120')));

    expect(Rx::retail('sa', $size)?->amount_minor)->toBe(10000);
});
