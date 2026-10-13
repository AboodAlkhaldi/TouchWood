<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Domain\Exception\AmountsInvalid;
use Modules\Pricing\Domain\Exception\DuplicateLines;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Dto\CartLineDto;
use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Pricing\Public\Dto\KeptPartDto;
use Modules\Pricing\Public\Dto\LinePriceDto;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| PricingApi (pricing.md §2.1, §1.6, §1.7, §1.11; §8 #2, #4, #16): each line priced from the
| materialized candidates in one read, lines back in the order given, an unpriced one named apart;
| the subtotals and when the prices stop applying; every amount through totals(); an order edited
| before it ships - kept parts as sold, added pieces at today's price, a wholesale band for the new
| total, on the added only, at the order's VAT rate. Sales and discounts arrive with their steps:
| their candidates are written here by hand.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow('2026-10-11 10:00:00+00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * A candidate a later step's change would write.
 */
function pricingApiCandidate(string $variantId, SaleMode $mode, int $amountMinor, PriceKind $kind, int $fromQuantity = 1, bool $alwaysWins = false, ?string $startsAt = null, ?string $endsAt = null): void
{
    DB::table('pricing.price_candidates')->insert([
        'store_id' => Rx::storeId('sa'), 'variant_id' => $variantId, 'mode' => $mode->value, 'from_quantity' => $fromQuantity,
        'amount_minor' => $amountMinor, 'currency_code' => 'SAR', 'kind' => $kind->value, 'always_wins' => $alwaysWins,
        'source_id' => in_array($kind, [PriceKind::Sale, PriceKind::Category], true) ? strtolower((string) Str::ulid()) : null,
        'starts_at' => $startsAt, 'ends_at' => $endsAt,
    ]);
}

function pricingApiStore(string $code = 'sa'): StoreId
{
    return StoreId::fromString(Rx::storeId($code));
}

/**
 * @return list<string> each line as "variant mode quantity listUnit unit kind listTotal total endsAt"
 */
function pricingApiLines(CartPricesDto $prices): array
{
    return array_map(static fn (LinePriceDto $line): string => implode(' ', [
        $line->variantId, $line->mode->value, $line->quantity, $line->listUnit->minorUnits, $line->unit->minorUnits,
        $line->kind->value, $line->listTotal->minorUnits, $line->total->minorUnits, $line->endsAt?->format('Y-m-d') ?? '-',
    ]), $prices->lines);
}

describe('prices()', function () {
    it('prices each line in the order given, names the unpriced apart, and adds them up at the store\'s VAT rate', function () {
        [$a, $b, $c] = Px::ready(['60 cm', '80 cm', '100 cm'])['variants'];
        Rx::set('sa', [$a => '100', $b => '50.50']);

        $prices = app(PricingApi::class)->prices(pricingApiStore(), [
            new CartLineDto($b, SaleMode::Retail, 2),
            new CartLineDto($c, SaleMode::Retail, 1),
            new CartLineDto(strtoupper($a), SaleMode::Retail, 3),
        ]);

        $upper = strtoupper($a);

        expect(pricingApiLines($prices))->toBe([
            "{$b} RETAIL 2 5050 5050 BASE 10100 10100 -",
            "{$upper} RETAIL 3 10000 10000 BASE 30000 30000 -",
        ])->and($prices->unpriced)->toBe([$c])
            ->and([$prices->grossSubtotal->minorUnits, $prices->netSubtotal->minorUnits, $prices->currencyCode, $prices->taxRateBasisPoints])->toBe([40100, 40100, 'SAR', 1500])
            ->and($prices->storeId)->toBe(Rx::storeId('sa'))
            ->and($prices->validUntil)->toBeNull();
    });

    it('takes a running sale, says when it ends, and lets a scheduled "always wins" cut that short', function () {
        [$a, $b, $c] = Px::ready(['60 cm', '80 cm', '100 cm'])['variants'];
        Rx::set('sa', [$a => '100', $b => '100', $c => '100']);
        pricingApiCandidate($a, SaleMode::Retail, 8000, PriceKind::Sale, startsAt: '2026-10-01 00:00:00+00', endsAt: '2026-10-20 00:00:00+00');
        pricingApiCandidate($c, SaleMode::Retail, 7000, PriceKind::Sale, startsAt: '2026-10-01 00:00:00+00', endsAt: '2026-10-25 00:00:00+00');
        pricingApiCandidate($b, SaleMode::Retail, 9000, PriceKind::Sale, startsAt: '2026-10-01 00:00:00+00', endsAt: '2026-10-30 00:00:00+00');
        pricingApiCandidate($b, SaleMode::Retail, 9500, PriceKind::Sale, alwaysWins: true, startsAt: '2026-10-15 00:00:00+00', endsAt: '2026-10-25 00:00:00+00');

        // The earliest end in the middle, so the quote's end is the earliest, not the first or the last line's.
        $prices = app(PricingApi::class)->prices(pricingApiStore(), [new CartLineDto($a, SaleMode::Retail, 1), new CartLineDto($b, SaleMode::Retail, 1), new CartLineDto($c, SaleMode::Retail, 1)]);

        expect(pricingApiLines($prices))->toBe([
            "{$a} RETAIL 1 10000 8000 SALE 10000 8000 2026-10-20",
            "{$b} RETAIL 1 10000 9000 SALE 10000 9000 2026-10-15",
            "{$c} RETAIL 1 10000 7000 SALE 10000 7000 2026-10-25",
        ])->and($prices->validUntil?->format('Y-m-d'))->toBe('2026-10-15')
            ->and([$prices->grossSubtotal->minorUnits, $prices->netSubtotal->minorUnits])->toBe([30000, 24000]);
    });

    it('gives a wholesale line its band, as the line\'s normal price', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        pricingApiCandidate($size, SaleMode::Wholesale, 8500, PriceKind::Quantity, 20);
        pricingApiCandidate($size, SaleMode::Wholesale, 7800, PriceKind::Quantity, 100);

        $prices = app(PricingApi::class)->prices(pricingApiStore(), [new CartLineDto($size, SaleMode::Wholesale, 150), new CartLineDto($size, SaleMode::Retail, 2)]);

        expect(pricingApiLines($prices))->toBe([
            "{$size} WHOLESALE 150 7800 7800 QUANTITY 1170000 1170000 -",
            "{$size} RETAIL 2 10000 10000 BASE 20000 20000 -",
        ]);
    });

    it('reads the candidates in one query, however many lines (§8 #4)', function () {
        $sizes = Px::ready(['60 cm', '80 cm', '100 cm'])['variants'];
        Rx::set('sa', array_fill_keys($sizes, '10'));
        $store = pricingApiStore();
        $queries = Cx::recordQueries();

        app(PricingApi::class)->prices($store, array_map(static fn (string $id): CartLineDto => new CartLineDto($id, SaleMode::Retail, 1), $sizes));

        // One read of Pricing's, and nothing of Catalog's: no category, no product, at request time.
        expect(array_values(array_filter($queries->getArrayCopy(), static fn (array $query): bool => str_contains($query['sql'], '"pricing"'))))->toHaveCount(1)
            ->and(array_values(array_filter($queries->getArrayCopy(), static fn (array $query): bool => str_contains($query['sql'], '"catalog"'))))->toBe([]);
    });

    it('refuses a variant and mode given twice, however its id is written, a quantity below 1 and a store that does not exist', function () {
        $size = Px::ready()['variants'][0];
        $api = app(PricingApi::class);

        expect(fn () => $api->prices(pricingApiStore(), [new CartLineDto($size, SaleMode::Retail, 1), new CartLineDto(strtoupper($size), SaleMode::Retail, 2)]))->toThrow(DuplicateLines::class)
            ->and(Rx::invalid(fn () => $api->prices(pricingApiStore(), [new CartLineDto($size, SaleMode::Retail, 0)]))?->attribute)->toBe('quantity')
            ->and(Rx::invalid(fn () => $api->prices(StoreId::fromString(strtolower((string) Str::ulid())), []))?->attribute)->toBe('store')
            ->and($api->prices(pricingApiStore(), [new CartLineDto($size, SaleMode::Retail, 1), new CartLineDto($size, SaleMode::Wholesale, 1)])->unpriced)->toBe([$size, $size])
            ->and(Rx::invalid(fn () => $api->prices(pricingApiStore(), [new CartLineDto($size, SaleMode::Retail, PHP_INT_MAX)]))?->attribute)->toBe('quantity');

        // A total beyond what minor units keep is refused as an amount, never a server error.
        Rx::set('sa', [$size => '100000000']);

        expect(fn () => $api->prices(pricingApiStore(), [new CartLineDto($size, SaleMode::Retail, 2147483647)]))->toThrow(AmountsInvalid::class);
    });
});

it('works every amount of an order through totals()', function () {
    $size = Px::ready()['variants'][0];
    Rx::set('sa', [$size => '100']);
    $api = app(PricingApi::class);
    $prices = $api->prices(pricingApiStore(), [new CartLineDto($size, SaleMode::Retail, 2)]);

    $totals = $api->totals($prices, Money::of(1000, 'SAR'), Money::zero('SAR'), Money::of(2500, 'SAR'));

    expect([$totals->goodsTotal->minorUnits, $totals->taxableBase->minorUnits, $totals->vat->minorUnits, $totals->orderTotal->minorUnits])->toBe([19000, 21500, 3225, 24725]);
});

describe('pricesForEdit() (§1.11)', function () {
    it('keeps the parts as sold and prices the added pieces by today\'s band for the new total, at the order\'s VAT rate', function () {
        $size = Px::ready()['variants'][0];
        Rx::set('sa', [$size => '100']);
        pricingApiCandidate($size, SaleMode::Wholesale, 8500, PriceKind::Quantity, 20);
        pricingApiCandidate($size, SaleMode::Wholesale, 7800, PriceKind::Quantity, 100);
        pricingApiCandidate($size, SaleMode::Wholesale, 7000, PriceKind::Quantity, 500);
        $at78 = Money::of(7800, 'SAR');

        $prices = app(PricingApi::class)->pricesForEdit(pricingApiStore(), [
            new KeptPartDto($size, SaleMode::Wholesale, 60, $at78, $at78, PriceKind::Quantity),
            new KeptPartDto($size, SaleMode::Wholesale, 40, $at78, $at78, PriceKind::Quantity),
        ], [new CartLineDto($size, SaleMode::Wholesale, 400)], 1000);

        expect(pricingApiLines($prices))->toBe([
            "{$size} WHOLESALE 60 7800 7800 QUANTITY 468000 468000 -",
            "{$size} WHOLESALE 40 7800 7800 QUANTITY 312000 312000 -",
            "{$size} WHOLESALE 400 7000 7000 QUANTITY 2800000 2800000 -",
        ])->and($prices->taxRateBasisPoints)->toBe(1000)
            ->and($prices->netSubtotal->minorUnits)->toBe(3580000)
            ->and(app(PricingApi::class)->totals($prices, Money::zero('SAR'), Money::zero('SAR'), Money::zero('SAR'))->vat->minorUnits)->toBe(358000);

        // Kept pieces of the other way of buying do not count toward the band.
        $other = app(PricingApi::class)->pricesForEdit(pricingApiStore(), [
            new KeptPartDto($size, SaleMode::Retail, 100, Money::of(10000, 'SAR'), Money::of(10000, 'SAR'), PriceKind::Base),
        ], [new CartLineDto($size, SaleMode::Wholesale, 400)], 1000);

        expect(pricingApiLines($other)[1])->toBe("{$size} WHOLESALE 400 7800 7800 QUANTITY 3120000 3120000 -")
            ->and(fn () => app(PricingApi::class)->pricesForEdit(pricingApiStore(), [
                new KeptPartDto($size, SaleMode::Retail, 1, Money::of(100, 'EGP'), Money::of(100, 'EGP'), PriceKind::Base),
            ], [], 1000))->toThrow(AmountsInvalid::class);
    });

    it('ends with the added pieces\' prices only, names an added size with no price, and refuses an added line given twice', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Rx::set('sa', [$a => '100']);
        pricingApiCandidate($a, SaleMode::Retail, 9000, PriceKind::Sale, startsAt: '2026-10-01 00:00:00+00', endsAt: '2026-10-20 00:00:00+00');
        $old = Money::of(12000, 'SAR');
        $api = app(PricingApi::class);

        $prices = $api->pricesForEdit(pricingApiStore(), [new KeptPartDto($a, SaleMode::Retail, 1, $old, $old, PriceKind::Base)], [
            new CartLineDto($a, SaleMode::Retail, 1),
            new CartLineDto($b, SaleMode::Retail, 1),
        ], 1500);

        expect(pricingApiLines($prices))->toBe([
            "{$a} RETAIL 1 12000 12000 BASE 12000 12000 -",
            "{$a} RETAIL 1 10000 9000 SALE 10000 9000 2026-10-20",
        ])->and($prices->unpriced)->toBe([$b])
            ->and($prices->validUntil?->format('Y-m-d'))->toBe('2026-10-20')
            ->and(fn () => $api->pricesForEdit(pricingApiStore(), [], [new CartLineDto($a, SaleMode::Retail, 1), new CartLineDto($a, SaleMode::Retail, 1)], 1500))->toThrow(DuplicateLines::class)
            ->and(Rx::invalid(fn () => $api->pricesForEdit(pricingApiStore(), [new KeptPartDto($a, SaleMode::Retail, 0, $old, $old, PriceKind::Base)], [], 1500))?->attribute)->toBe('quantity');
    });
});
