<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\CorrectStoreFillCode\CorrectStoreFillCode;
use Modules\Catalog\Application\Command\CorrectStoreFillCode\CorrectStoreFillCodeHandler;
use Modules\Catalog\Application\Command\RemoveStoreFillItems\RemoveStoreFillItems;
use Modules\Catalog\Application\Command\RemoveStoreFillItems\RemoveStoreFillItemsHandler;
use Modules\Catalog\Application\Command\SwitchOnStoreFillItems\SwitchOnStoreFillItems;
use Modules\Catalog\Application\Command\SwitchOnStoreFillItems\SwitchOnStoreFillItemsHandler;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFill;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFillHandler;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The admins' store file (catalog.md §1.3; amendment 6(g), (h)): `catalog.listing.fill` in that store,
| an admin role's job. Codes and prices, stock optional; it never creates or changes a product. Its
| items are switched on in the store — the variants carrying each code of a ready product — chosen or
| every one ready; an unknown code is corrected or removed. Everything audited in the store.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Fx::actAsAdmin(['sa'], [CatalogPermissions::LISTING_FILL]);
});

afterEach(function () {
    Ix::cleanUp();
});

/**
 * @param  list<array<string, mixed>>  $items
 */
function catalogFill(array $items, string $store = 'sa'): string
{
    return app(UploadStoreFillHandler::class)->handle(new UploadStoreFill(Fx::storeId($store), Ix::temp(json_encode(['format' => 'touchwood-store-fill/1', 'items' => $items], JSON_THROW_ON_ERROR)), 'sa-prices.json'));
}

function catalogFillItem(string $importId, int $number): string
{
    return (string) DB::table('catalog.store_fill_items')->where('import_id', $importId)->where('number', $number)->value('id');
}

/**
 * @return list<string>
 */
function catalogFillStates(string $importId): array
{
    return array_values(array_map('strval', DB::table('catalog.store_fill_items')->where('import_id', $importId)->orderBy('number')->pluck('state')->all()));
}

/**
 * @return list<string> the variants on in the store
 */
function catalogFillOn(string $store = 'sa'): array
{
    return array_values(array_map('strval', DB::table('catalog.store_variants')->where('store_id', Fx::storeId($store))->where('is_active', true)->orderBy('variant_id')->pluck('variant_id')->all()));
}

describe('the file', function () {
    it('is kept with its items open, as the file gave them, audited in the store, changing no product', function () {
        $products = DB::table('catalog.products')->count();

        $id = catalogFill([['code' => '1304', 'price' => 120.5, 'stock' => 40], ['code' => '1305', 'price' => 125]]);

        $import = DB::table('catalog.imports')->where('id', $id)->first() ?? throw new LogicException('No file.');
        expect([$import->kind, $import->state, $import->store_id, $import->file_name])->toBe(['STORE_FILL', 'OPEN', Fx::storeId('sa'), 'sa-prices.json'])
            ->and(DB::table('catalog.store_fill_items')->where('import_id', $id)->orderBy('number')->get(['number', 'code', 'price', 'stock', 'state'])->map(fn ($row): array => (array) $row)->all())
            ->toBe([
                ['number' => 1, 'code' => '1304', 'price' => '120.5', 'stock' => 40, 'state' => 'OPEN'],
                ['number' => 2, 'code' => '1305', 'price' => '125', 'stock' => null, 'state' => 'OPEN'],
            ])
            ->and(DB::table('platform.audit_entries')->where('action', 'catalog.store_fill.added')->where('subject_id', $id)->value('store_id'))->toBe(Fx::storeId('sa'))
            ->and(DB::table('catalog.products')->count())->toBe($products);
    });

    it('is refused when not in its format, every problem listed', function () {
        expect(fn () => catalogFill([['code' => '1304', 'price' => 1], ['code' => '1304', 'price' => -2]]))->toThrow(ImportRefused::class);
        expect(DB::table('catalog.imports')->count())->toBe(0);
    });

    it('is an admin role\'s job in that store only', function () {
        expect(fn () => catalogFill([['code' => '1', 'price' => 1]], 'eg'))->toThrow(Unauthorized::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        expect(catalogFill([['code' => '1', 'price' => 1]], 'eg'))->toBeString();
    });
});

describe('switching on', function () {
    it('switches on every ready one: the variants carrying its code, in that store only', function () {
        $ready = Fx::asSystem(fn (): array => Px::ready(['60 cm', '80 cm']));
        [$sixty, $eighty] = $ready['variants'];
        $code = (string) DB::table('catalog.variants')->where('id', $sixty)->value('code');
        $draft = Px::product();
        Px::variant($draft, '7700');
        $import = catalogFill([['code' => $code, 'price' => 10], ['code' => '999999', 'price' => 1], ['code' => '7700', 'price' => 2]]);

        expect(app(SwitchOnStoreFillItemsHandler::class)->handle(new SwitchOnStoreFillItems($import, null)))->toBe(1)
            ->and(catalogFillStates($import))->toBe(['ON', 'OPEN', 'OPEN'])
            ->and(catalogFillOn())->toBe([$sixty])
            ->and(catalogFillOn('eg'))->toBe([])
            ->and(DB::table('platform.audit_entries')->where('action', 'catalog.listing.chosen')->where('subject_id', $ready['product'])->value('store_id'))->toBe(Fx::storeId('sa'))
            ->and(DB::table('catalog.variants')->where('id', $eighty)->exists())->toBeTrue();
    });

    it('names a chosen item that cannot be switched on, and switches on none', function () {
        $ready = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $import = catalogFill([['code' => $code, 'price' => 10], ['code' => '999999', 'price' => 1]]);

        expect(fn () => app(SwitchOnStoreFillItemsHandler::class)->handle(new SwitchOnStoreFillItems($import, [catalogFillItem($import, 1), catalogFillItem($import, 2)])))
            ->toThrow(InvalidCatalogAttribute::class, 'Invalid item 2: an open item whose code a ready product holds');
        expect(catalogFillStates($import))->toBe(['OPEN', 'OPEN'])
            ->and(catalogFillOn())->toBe([]);
    });
});

describe('mending the file', function () {
    it('corrects an open item\'s code, to one no other item has', function () {
        $ready = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $import = catalogFill([['code' => '999999', 'price' => 10], ['code' => '5', 'price' => 1]]);
        $item = catalogFillItem($import, 1);

        expect(fn () => app(CorrectStoreFillCodeHandler::class)->handle(new CorrectStoreFillCode($import, $item, '5')))->toThrow(InvalidCatalogAttribute::class, 'a code no other item of the file has')
            ->and(fn () => app(CorrectStoreFillCodeHandler::class)->handle(new CorrectStoreFillCode($import, $item, 'x1')))->toThrow(InvalidCatalogAttribute::class);

        app(CorrectStoreFillCodeHandler::class)->handle(new CorrectStoreFillCode($import, $item, $code));
        expect(app(SwitchOnStoreFillItemsHandler::class)->handle(new SwitchOnStoreFillItems($import, [$item])))->toBe(1)
            ->and(catalogFillOn())->toBe($ready['variants'])
            ->and(fn () => app(CorrectStoreFillCodeHandler::class)->handle(new CorrectStoreFillCode($import, $item, '6')))->toThrow(InvalidCatalogAttribute::class, 'an item still open')
            ->and(Fx::audits('catalog.store_fill_item.corrected', $item))->toBe(1);
    });

    it('removes open items, kept on the page as removed', function () {
        $import = catalogFill([['code' => '999999', 'price' => 10], ['code' => '5', 'price' => 1]]);

        expect(app(RemoveStoreFillItemsHandler::class)->handle(new RemoveStoreFillItems($import, [catalogFillItem($import, 1)])))->toBe(1)
            ->and(catalogFillStates($import))->toBe(['REMOVED', 'OPEN'])
            ->and(fn () => app(RemoveStoreFillItemsHandler::class)->handle(new RemoveStoreFillItems($import, [catalogFillItem($import, 1)])))->toThrow(InvalidCatalogAttribute::class, 'Invalid item 1: an item still open');
    });
});
