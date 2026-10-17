<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Contracts\ProviderPrices;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\ProviderPricesResult;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The provider's prices, written by Sync (pricing.md §2.6; sync.md §1.4, §9.3 #17): the base price
| taken with source PROVIDER, audited with its read, the same price nothing; off and draft sizes named;
| archived, unknown and impossible prices named and left alone; a sale the price takes past the ceiling
| stopped on that size - left, ended or removed; a fixed-amount category discount skipping the size
| while it would go past it; a store not wired, or unwired under the lock, left alone.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-17 10:00:00+00');
    Queue::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function providerPricesWired(bool $wired = true): void
{
    app()->instance(StoreWiring::class, new class($wired) implements StoreWiring
    {
        public function __construct(private bool $wired) {}

        public function isWired(StoreId $store): bool
        {
            return $this->wired;
        }
    });
}

/**
 * @param  array<string, string>  $prices
 */
function providerPricesApply(array $prices, string $readRef = 'read-1'): ProviderPricesResult
{
    return Fx::asSystem(fn (): ProviderPricesResult => app(ProviderPrices::class)->apply(StoreId::fromString(Rx::storeId('sa')), $prices, $readRef));
}

function providerPricesUnit(string $size): ?int
{
    return app(PricingApi::class)->prices(StoreId::fromString(Rx::storeId('sa')), [new CartLineDto($size, SaleMode::Retail, 1)])->lines[0]->unit->minorUnits ?? null;
}

/**
 * @param  list<string>  $sizes
 */
function providerPricesSale(array $sizes, string $price, ?string $startsAt = null, ?string $percent = null): string
{
    return Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Rx::storeId('sa'), null, $sizes, $percent, $percent === null ? $price : null, $startsAt, null)));
}

function providerPricesSaleRow(string $saleId): ?stdClass
{
    $row = DB::table('pricing.sales')->where('id', $saleId)->first();

    return $row instanceof stdClass ? $row : null;
}

/**
 * @return list<string>
 */
function providerPricesSaleSizes(string $saleId): array
{
    $sizes = DB::table('pricing.sale_variants')->where('sale_id', $saleId)->orderBy('variant_id')->pluck('variant_id')->all();

    return array_values(array_map('strval', $sizes));
}

describe('the provider\'s prices', function () {
    it('takes each price with source PROVIDER, audited from and to with its read, and the same price again changes nothing', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Rx::set('sa', [$a => '100']);
        providerPricesWired();

        $result = providerPricesApply([$a => '120.50', $b => '80']);

        expect($result->wired)->toBeTrue()
            ->and($result->changed)->toEqualCanonicalizing([$a, $b])
            ->and([$result->archived, $result->notFound, $result->invalid, $result->salesStopped, $result->discountsSkipped])->toBe([[], [], [], [], []])
            ->and(Rx::retail('sa', $a)?->amount_minor)->toBe(12050)
            ->and(Rx::retail('sa', $a)?->source)->toBe('PROVIDER')
            ->and(Rx::auditChanges('pricing.retail_price.provided', $a))->toEqual(['amount' => ['100.00', '120.50'], 'read' => [null, 'read-1']])
            ->and(Rx::auditChanges('pricing.retail_price.provided', $b)['amount'])->toBe([null, '80.00'])
            ->and(Rx::told('sa', $a)[0] ?? null)->toBe(12050);

        $audits = Fx::audits('pricing.retail_price.provided');

        expect(providerPricesApply([$a => '120.5', $b => '80.00'], 'read-2')->changed)->toBe([])
            ->and(Fx::audits('pricing.retail_price.provided'))->toBe($audits);
    });

    it('names a size switched off in the store, or of a draft product, and prices it all the same', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$on, $off] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $ready['product'], true)));
        DB::table('catalog.store_variants')->where('store_id', Rx::storeId('sa'))->where('variant_id', $off)->delete();
        // Switched on in the store, its product a draft again: named for being a draft.
        $drafted = Px::ready(['90 cm']);
        $draft = $drafted['variants'][0];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Rx::storeId('sa'), $drafted['product'], true)));
        DB::table('catalog.products')->where('id', $drafted['product'])->update(['stage' => 'DRAFT']);
        providerPricesWired();

        $result = providerPricesApply([$on => '50', $off => '60', $draft => '70']);

        expect($result->changed)->toEqualCanonicalizing([$on, $off, $draft])
            ->and($result->offOrDraft)->toEqualCanonicalizing([$off, $draft])
            ->and(Rx::retail('sa', $draft)?->amount_minor)->toBe(7000);
    });

    it('leaves an archived size, one of an archived product, one not found, a price that is not one and a second id for a size alone, naming each', function () {
        [$kept, $archived] = Px::ready(['60 cm', '80 cm'])['variants'];
        $ofArchived = Px::ready(['90 cm'])['variants'][0];
        DB::table('catalog.variants')->where('id', $archived)->update(['is_archived' => true]);
        DB::table('catalog.products')->where('id', DB::table('catalog.variants')->where('id', $ofArchived)->value('product_id'))->update(['stage' => 'ARCHIVED', 'archived_from' => 'READY']);
        $missing = strtolower((string) Str::ulid());
        [$zero, $negative, $text, $precise] = Px::ready(['1', '2', '3', '4'])['variants'];
        providerPricesWired();

        $result = providerPricesApply([
            $kept => '10', $archived => '10', $ofArchived => '10', $missing => '10',
            $zero => '0', $negative => '-5', $text => 'ten', $precise => '1.234', strtoupper($kept) => '12',
        ]);

        expect($result->changed)->toBe([$kept])
            ->and($result->archived)->toEqualCanonicalizing([$archived, $ofArchived])
            ->and($result->notFound)->toBe([$missing])
            ->and($result->invalid)->toBe([$zero, $negative, $text, $precise, strtoupper($kept)])
            ->and(Rx::retail('sa', $kept)?->amount_minor)->toBe(1000)
            ->and(Rx::retail('sa', $archived))->toBeNull()
            ->and(Rx::retail('sa', $zero))->toBeNull();
    });

    it('writes nothing in a store that is not wired, and stops at the batch where it finds it unwired under the lock', function () {
        $size = Px::ready()['variants'][0];
        providerPricesWired(false);

        expect(providerPricesApply([$size => '10'])->wired)->toBeFalse()
            ->and(Rx::retail('sa', $size))->toBeNull();

        app()->instance(StoreWiring::class, new class implements StoreWiring
        {
            private int $asked = 0;

            public function isWired(StoreId $store): bool
            {
                return $this->asked++ === 0;
            }
        });

        $result = providerPricesApply([$size => '10']);

        expect($result->wired)->toBeFalse()
            ->and($result->changed)->toBe([])
            ->and(Rx::retail('sa', $size))->toBeNull();
    });

    it('refuses a read reference the audit cannot hold', function (string $readRef) {
        $size = Px::ready()['variants'][0];
        providerPricesWired();

        expect(fn () => providerPricesApply([$size => '10'], $readRef))->toThrow(InvalidArgumentException::class)
            ->and(Rx::retail('sa', $size))->toBeNull();
    })->with(['empty' => '', 'a space' => 'read 1', 'too long' => str_repeat('r', 65)]);
});

describe('the ceiling (sync.md §1.4 #5, #6; 30% by default)', function () {
    it('stops a running sale on a size the price takes past the ceiling, and ends it when no size is left', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Rx::set('sa', [$a => '100', $b => '100']);
        $sale = providerPricesSale([$a, $b], '80');
        providerPricesWired();
        CarbonImmutable::setTestNow('2026-10-17 11:00:00+00');

        // 80 on 200 is 60% off: past 30%.
        $first = providerPricesApply([$a => '200']);

        expect($first->salesStopped)->toBe([['saleId' => $sale, 'variantId' => $a, 'outcome' => 'left']])
            ->and(providerPricesSaleSizes($sale))->toBe([$b])
            ->and(providerPricesUnit($a))->toBe(20000)
            ->and(providerPricesUnit($b))->toBe(8000)
            ->and(Fx::audits('pricing.sale.changed'))->toBe(1);

        $second = providerPricesApply([$b => '200'], 'read-2');

        expect($second->salesStopped)->toBe([['saleId' => $sale, 'variantId' => $b, 'outcome' => 'ended']])
            ->and(providerPricesSaleRow($sale)?->ends_at)->not->toBeNull()
            ->and(providerPricesUnit($b))->toBe(20000)
            ->and(Fx::audits('pricing.sale.ended'))->toBe(1);
    });

    it('removes a scheduled sale left with no size, and leaves a percentage sale - as deep on every price - alone', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Rx::set('sa', [$a => '100', $b => '100']);
        $scheduled = providerPricesSale([$a], '80', startsAt: '2026-10-20 10:00:00+00');
        $percent = providerPricesSale([$b], '', percent: '25');
        providerPricesWired();

        $result = providerPricesApply([$a => '200', $b => '500']);

        expect($result->salesStopped)->toBe([['saleId' => $scheduled, 'variantId' => $a, 'outcome' => 'removed']])
            ->and(providerPricesSaleRow($scheduled))->toBeNull()
            ->and(providerPricesSaleRow($percent))->not->toBeNull()
            ->and(providerPricesUnit($b))->toBe(37500)
            ->and(Fx::audits('pricing.sale.removed'))->toBe(1);
    });

    it('lets a fixed-amount category discount skip a size the price takes past the ceiling, and reach it again once it allows', function () {
        $category = Px::category('Kitchens');
        [$a, $b] = Px::ready(['60 cm', '80 cm'], $category)['variants'];
        Rx::set('sa', [$a => '200', $b => '200']);
        // 50 off 200 is 25%.
        $discount = Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Rx::storeId('sa'), $category, null, '50', 'BOTH', null, null)));
        providerPricesWired();

        // 50 off 120 is 42%: past 30%.
        $result = providerPricesApply([$a => '120']);

        expect($result->discountsSkipped)->toBe([['discountId' => $discount, 'variantId' => $a]])
            ->and($result->changed)->toBe([$a])
            ->and(providerPricesUnit($a))->toBe(12000)
            ->and(providerPricesUnit($b))->toBe(15000);

        expect(providerPricesApply([$a => '180'], 'read-2')->discountsSkipped)->toBe([])
            ->and(providerPricesUnit($a))->toBe(13000);
    });
});
