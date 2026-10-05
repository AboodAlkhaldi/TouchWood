<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AcceptImportedProducts\AcceptImportedProducts;
use Modules\Catalog\Application\Command\AcceptImportedProducts\AcceptImportedProductsHandler;
use Modules\Catalog\Application\Command\ArchiveImportedProducts\ArchiveImportedProducts;
use Modules\Catalog\Application\Command\ArchiveImportedProducts\ArchiveImportedProductsHandler;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodes;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodesHandler;
use Modules\Catalog\Application\Command\DeleteImportedProducts\DeleteImportedProducts;
use Modules\Catalog\Application\Command\DeleteImportedProducts\DeleteImportedProductsHandler;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Accepting, archiving and deleting an import's products (catalog.md §1.12, page part 4; amendment
| 6(e), (f)): a Super Admin's, once the products are in. Accepted, a product is made ready — not
| published —, switched on in the stores the file named, and related to the ready products whose
| codes it named; "every ready one" leaves the rest, a chosen one that cannot be is named and nothing
| changes. Only what the import created and nobody accepted is archived or deleted.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

afterEach(function () {
    Ix::cleanUp();
});

/**
 * A product the file gives whole: both names and descriptions, a lowest category, a photo.
 *
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function catalogAcceptProduct(string $code, string $category, array $with = []): array
{
    return Ix::product($code, ['description' => ['ar' => 'وصف', 'en' => 'About it'], 'category' => $category, 'photos' => ["{$code}.jpg"], ...$with]);
}

/**
 * These products brought in from a zip with a photo each, the photos' sizes made ready.
 *
 * @param  list<array<string, mixed>>  $products
 */
function catalogAcceptBroughtIn(array $products): string
{
    $files = ['products.json' => Ix::json($products)];

    foreach ($products as $index => $product) {
        foreach ((array) ($product['photos'] ?? []) as $path) {
            $files[(string) $path] = Ix::image((string) $path, 20 + $index);
        }
    }

    $import = Ix::upload(Ix::zip($files));
    Ix::decideNames($import);
    Ix::bringIn($import);
    // The sizes Platform makes from the queue, made ready (a ready product needs a ready photo).
    DB::table('platform.media')->update(['variants_status' => 'READY', 'variants_generated_at' => now()]);

    return $import;
}

function catalogAcceptState(string $importId, int $number): string
{
    return (string) DB::table('catalog.import_products')->where('import_id', $importId)->where('number', $number)->value('state');
}

describe('accepting', function () {
    it('is a Super Admin\'s, once the products are in', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);

        expect(fn () => app(AcceptImportedProductsHandler::class)->handle(new AcceptImportedProducts($import, null)))->toThrow(ImportClosed::class);

        Fx::actAsAdmin(['*'], CatalogPermissions::jobs());
        expect(fn () => app(AcceptImportedProductsHandler::class)->handle(new AcceptImportedProducts($import, null)))->toThrow(Unauthorized::class);
    });

    it('makes every ready one ready, switches it on in the file\'s stores, and relates it to the ready products it names', function () {
        $category = (string) DB::table('catalog.categories')->where('id', Px::category('Hinges'))->value('name_en');
        $import = catalogAcceptBroughtIn([
            catalogAcceptProduct('6100', $category, ['stores' => ['sa' => ['price' => 10], 'zz' => ['price' => 1]], 'related' => ['6300'], 'goes_with' => ['6200']]),
            catalogAcceptProduct('6200', $category, ['description' => ['ar' => 'وصف']]),
            catalogAcceptProduct('6300', $category),
        ]);
        [$first, $second, $third] = [Ix::broughtIn($import, 1), Ix::broughtIn($import, 2), Ix::broughtIn($import, 3)];

        expect(app(AcceptImportedProductsHandler::class)->handle(new AcceptImportedProducts($import, null)))->toBe(2)
            ->and([catalogAcceptState($import, 1), catalogAcceptState($import, 2), catalogAcceptState($import, 3)])->toBe(['ACCEPTED', 'IN', 'ACCEPTED'])
            ->and(DB::table('catalog.products')->whereIn('id', [$first, $second, $third])->orderBy('id')->pluck('stage', 'id')->all())->toEqual([$first => 'READY', $second => 'DRAFT', $third => 'READY'])
            ->and(DB::table('catalog.store_products')->where('product_id', $first)->pluck('store_id')->all())->toBe([Fx::storeId('sa')])
            ->and(DB::table('catalog.store_variants')->where('product_id', $first)->where('store_id', Fx::storeId('sa'))->pluck('is_active')->all())->toBe([true])
            ->and(DB::table('catalog.store_products')->where('product_id', $third)->exists())->toBeFalse()
            ->and(DB::table('catalog.product_relations')->where('product_id', $first)->pluck('related_id', 'kind')->all())->toBe(['RELATED' => $third])
            ->and(Fx::audits('catalog.import.accepted', $import))->toBe(1);
    });

    it('names a chosen one that cannot be accepted, and accepts none of them', function () {
        $category = (string) DB::table('catalog.categories')->where('id', Px::category('Hinges'))->value('name_en');
        $import = catalogAcceptBroughtIn([
            catalogAcceptProduct('6100', $category),
            catalogAcceptProduct('6200', $category, ['description' => ['ar' => 'وصف']]),
        ]);

        expect(fn () => app(AcceptImportedProductsHandler::class)->handle(new AcceptImportedProducts($import, [Ix::productId($import, 1), Ix::productId($import, 2)])))
            ->toThrow(InvalidCatalogAttribute::class, 'Invalid product 2: ready to accept: it lacks description_en');
        expect(catalogAcceptState($import, 1))->toBe('IN')
            ->and(DB::table('catalog.products')->where('id', Ix::broughtIn($import, 1))->value('stage'))->toBe('DRAFT');
    });

    it('accepts a product it updated, already ready, switching it on in the file\'s stores', function () {
        $ready = Px::ready(['60 cm']);
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $width = (string) DB::table('catalog.attributes')->where('id', $ready['width'])->value('name_en');
        $set = (string) DB::table('catalog.attribute_sets')->where('id', DB::table('catalog.products')->where('id', $ready['product'])->value('attribute_set_id'))->value('name_en');
        $import = Ix::uploadProducts([Ix::product($code, [
            'name' => ['ar' => 'محدث'],
            'attribute_set' => $set,
            'variants' => [['code' => $code, 'values' => [$width => '60 cm']]],
            'stores' => ['eg' => ['price' => 100]],
        ])]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));
        Ix::bringIn($import);

        expect(catalogAcceptState($import, 1))->toBe('UPDATED');
        expect(app(AcceptImportedProductsHandler::class)->handle(new AcceptImportedProducts($import, [Ix::productId($import, 1)])))->toBe(1)
            ->and(DB::table('catalog.store_products')->where('product_id', $ready['product'])->pluck('store_id')->all())->toBe([Fx::storeId('eg')]);
    });
});

describe('archiving and deleting', function () {
    it('archives or deletes only a product the import created and nobody accepted', function () {
        $category = (string) DB::table('catalog.categories')->where('id', Px::category('Hinges'))->value('name_en');
        $import = catalogAcceptBroughtIn([
            catalogAcceptProduct('6100', $category),
            catalogAcceptProduct('6200', $category),
            catalogAcceptProduct('6300', $category),
        ]);
        [$first, $second] = [Ix::broughtIn($import, 1), Ix::broughtIn($import, 2)];
        app(AcceptImportedProductsHandler::class)->handle(new AcceptImportedProducts($import, [Ix::productId($import, 3)]));

        expect(app(ArchiveImportedProductsHandler::class)->handle(new ArchiveImportedProducts($import, [Ix::productId($import, 1)])))->toBe(1)
            ->and(app(DeleteImportedProductsHandler::class)->handle(new DeleteImportedProducts($import, [Ix::productId($import, 2)])))->toBe(1)
            ->and([catalogAcceptState($import, 1), catalogAcceptState($import, 2)])->toBe(['ARCHIVED', 'DELETED'])
            ->and(DB::table('catalog.products')->where('id', $first)->value('stage'))->toBe('ARCHIVED')
            ->and(DB::table('catalog.products')->where('id', $second)->exists())->toBeFalse()
            ->and(DB::table('catalog.import_products')->where('import_id', $import)->where('number', 2)->value('product_id'))->toBeNull()
            ->and(DB::table('catalog.product_codes')->where('code', '6200')->exists())->toBeFalse()
            ->and(fn () => app(ArchiveImportedProductsHandler::class)->handle(new ArchiveImportedProducts($import, [Ix::productId($import, 3)])))
            ->toThrow(InvalidCatalogAttribute::class, 'Invalid product 3: a product this import created and nobody accepted yet');
    });
});
