<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\SwitchOnStoreFillItems\SwitchOnStoreFillItems;
use Modules\Catalog\Application\Command\SwitchOnStoreFillItems\SwitchOnStoreFillItemsHandler;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFill;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFillHandler;
use Modules\Catalog\Application\Query\ViewStoreFill\ViewStoreFill;
use Modules\Catalog\Application\Query\ViewStoreFill\ViewStoreFillHandler;
use Modules\Catalog\Public\Contracts\ImportSection;
use Modules\Catalog\Public\Contracts\ImportSections;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Catalog\Public\Dto\ListingPrice;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What the modules above push into Catalog and add to its store files (catalog.md §2.2, §2.3;
| amendments 15, 16(h), 16(i)): `ListingFacts` keeps each fact in Catalog's own table, only the facts
| given; `ImportSections` collects each module's part of a store file, whose page shows their lines
| and whose switching on gives them the item's price and stock.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** @return array<string, mixed>|null */
function catalogFacts(string $store, string $variantId): ?array
{
    $row = DB::table('catalog.store_variant_facts')->where('store_id', Fx::storeId($store))->where('variant_id', $variantId)->first(['orderable', 'ending_soon', 'price_minor', 'price_before_minor', 'currency']);

    return $row === null ? null : (array) $row;
}

/** A section that writes down what it is given, as Pricing's or Inventory's would keep it. */
final class CatalogFactsRecordingSection implements ImportSection
{
    /** @var list<array{string, list<string>, string|null, int|null}> */
    public static array $accepted = [];

    public function lines(StoreId $store, ?string $price, ?int $stock): array
    {
        return ["Price {$price}", 'Stock '.($stock ?? 'none')];
    }

    public function accepted(StoreId $store, array $variantIds, ?string $price, ?int $stock): void
    {
        self::$accepted[] = [$store->value, $variantIds, $price, $stock];
    }
}

describe('the listing\'s facts', function () {
    it('keeps whether a variant can be ordered and whether it is ending soon, each fact apart, in that store only', function () {
        [$sixty, $eighty] = Px::ready(['60 cm', '80 cm'])['variants'];
        $facts = app(ListingFacts::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));

        $facts->orderable($sa, [$sixty, strtoupper($eighty), '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id'], false);
        $facts->endingSoon($sa, [$sixty], true);

        expect(catalogFacts('sa', $sixty))->toBe(['orderable' => false, 'ending_soon' => true, 'price_minor' => null, 'price_before_minor' => null, 'currency' => null])
            ->and(catalogFacts('sa', $eighty))->toMatchArray(['orderable' => false, 'ending_soon' => false])
            ->and(catalogFacts('eg', $sixty))->toBeNull()
            ->and(DB::table('catalog.store_variant_facts')->count())->toBe(2);

        $facts->orderable($sa, [$sixty], true);

        expect(catalogFacts('sa', $sixty))->toMatchArray(['orderable' => true, 'ending_soon' => true]);
    });

    it('keeps a variant\'s price now and before, and clears it when none is given, leaving the other facts', function () {
        [$sixty, $eighty] = Px::ready(['60 cm', '80 cm'])['variants'];
        $facts = app(ListingFacts::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));
        $facts->orderable($sa, [$sixty], false);

        $facts->prices($sa, [$sixty => new ListingPrice(Money::of(9_000, 'SAR'), Money::of(12_000, 'SAR')), $eighty => new ListingPrice(Money::of(15_000, 'SAR'))]);

        expect(catalogFacts('sa', $sixty))->toBe(['orderable' => false, 'ending_soon' => false, 'price_minor' => 9000, 'price_before_minor' => 12000, 'currency' => 'SAR'])
            ->and(catalogFacts('sa', $eighty))->toMatchArray(['price_minor' => 15000, 'price_before_minor' => null, 'currency' => 'SAR']);

        $facts->prices($sa, [$sixty => null]);

        expect(catalogFacts('sa', $sixty))->toBe(['orderable' => false, 'ending_soon' => false, 'price_minor' => null, 'price_before_minor' => null, 'currency' => null]);
    });

    it('takes a price before only above the price now, in its currency, and no sales ranks until stage 6', function () {
        expect(fn () => new ListingPrice(Money::of(100, 'SAR'), Money::of(100, 'SAR')))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new ListingPrice(Money::of(100, 'SAR'), Money::of(200, 'EGP')))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(ListingFacts::class)->salesRanks(StoreId::fromString(Fx::storeId('sa')), ['x' => 1]))->toThrow(LogicException::class);
    });
});

describe('the import\'s sections', function () {
    beforeEach(function () {
        CatalogFactsRecordingSection::$accepted = [];
    });

    it('takes a section once', function () {
        $sections = app(ImportSections::class);
        $sections->register('pricing', CatalogFactsRecordingSection::class);

        expect(fn () => $sections->register('inventory', CatalogFactsRecordingSection::class))->toThrow(LogicException::class, 'already registered by module "pricing"');
    });

    it('shows each section\'s lines on a store file\'s page, and gives it the price and stock of each item switched on', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty] = $ready['variants'];
        $code = (string) DB::table('catalog.variants')->where('id', $sixty)->value('code');
        Fx::actAsAdmin(['sa'], [CatalogPermissions::LISTING_FILL]);
        $import = app(UploadStoreFillHandler::class)->handle(new UploadStoreFill(Fx::storeId('sa'), Ix::temp((string) json_encode(['format' => 'touchwood-store-fill/1', 'items' => [['code' => $code, 'price' => 120.5, 'stock' => 40]]])), 'prices.json'));
        $page = fn () => app(ViewStoreFillHandler::class)->handle(new ViewStoreFill($import));

        expect($page()->pricesKept)->toBeFalse()
            ->and($page()->items[0]->lines)->toBe([]);

        app(ImportSections::class)->register('pricing', CatalogFactsRecordingSection::class);

        expect($page()->pricesKept)->toBeTrue()
            ->and($page()->items[0]->lines)->toBe(['Price 120.5', 'Stock 40']);

        app(SwitchOnStoreFillItemsHandler::class)->handle(new SwitchOnStoreFillItems($import, null));

        expect(CatalogFactsRecordingSection::$accepted)->toBe([[Fx::storeId('sa'), [$sixty], '120.5', 40]]);
    });
});
