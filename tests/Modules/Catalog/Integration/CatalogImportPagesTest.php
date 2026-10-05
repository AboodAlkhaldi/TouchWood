<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\RemoveStoreFillItems\RemoveStoreFillItems;
use Modules\Catalog\Application\Command\RemoveStoreFillItems\RemoveStoreFillItemsHandler;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWords;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWordsHandler;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFill;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFillHandler;
use Modules\Catalog\Application\Import\ImportSummary;
use Modules\Catalog\Application\Query\ListImports\ListImports;
use Modules\Catalog\Application\Query\ListImports\ListImportsHandler;
use Modules\Catalog\Application\Query\ListStoreFills\ListStoreFills;
use Modules\Catalog\Application\Query\ListStoreFills\ListStoreFillsHandler;
use Modules\Catalog\Application\Query\ViewImport\ImportProductView;
use Modules\Catalog\Application\Query\ViewImport\ViewImport;
use Modules\Catalog\Application\Query\ViewImport\ViewImportHandler;
use Modules\Catalog\Application\Query\ViewStoreFill\StoreFillItemView;
use Modules\Catalog\Application\Query\ViewStoreFill\ViewStoreFill;
use Modules\Catalog\Application\Query\ViewStoreFill\ViewStoreFillHandler;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The pages' reads (catalog.md §1.12, §1.3; amendment 6): a products file's page and the list of them,
| a Super Admin's; a store file's page and its store's list, `catalog.listing.fill` there. Each open
| store item stands as the catalog is now: ready, not ready (and what it lacks), archived, already on,
| or unknown. Prices and stock are shown, and not kept until stage 5.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local');
    Queue::fake();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

afterEach(function () {
    Ix::cleanUp();
});

/**
 * @param  list<array<string, mixed>>  $items
 */
function catalogPagesFill(array $items, string $store = 'sa'): string
{
    return app(UploadStoreFillHandler::class)->handle(new UploadStoreFill(Fx::storeId($store), Ix::temp(json_encode(['format' => 'touchwood-store-fill/1', 'items' => $items], JSON_THROW_ON_ERROR)), 'prices.json'));
}

describe('a products file\'s page', function () {
    it('shows the names to decide and the products as they will come in, with their stores and decisions', function () {
        $import = Ix::uploadProducts([
            Ix::product('1', ['brand' => 'Blumm', 'stores' => ['sa' => ['price' => 120.5, 'stock' => 4], 'zz' => ['price' => 1]]]),
            Ix::product('2'),
        ]);
        app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, [Ix::productId($import, 2)], ['درج'], 'ADD'));

        $view = app(ViewImportHandler::class)->handle(new ViewImport($import));

        expect([$view->state, $view->failure, $view->withPhotos, $view->pricesKept])->toBe(['DECIDING', null, false, false])
            ->and(array_map(static fn ($name): array => [$name->kind, $name->written, $name->products, $name->decision], $view->names))->toBe([['BRAND', 'Blumm', 1, null]])
            ->and(array_map(static fn (ImportProductView $product): array => [$product->number, $product->codes, $product->state, $product->changed], $view->products))->toBe([[1, ['1'], 'WAITING', false], [2, ['2'], 'WAITING', true]])
            ->and(array_map(static fn ($store): array => [$store->code, $store->known, $store->price, $store->stock], $view->products[0]->stores))->toBe([['sa', true, '120.5', 4], ['zz', false, '1', null]]);
    });

    it('shows what a draft brought in still lacks, and why bringing in failed', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        Ix::bringIn($import);

        $view = app(ViewImportHandler::class)->handle(new ViewImport($import));
        expect([$view->state, $view->products[0]->state])->toBe(['IN', 'IN'])
            ->and($view->products[0]->missing)->toBe(['description_ar', 'description_en', 'category', 'photos']);

        $failed = Ix::uploadProducts([Ix::product('2')]);
        DB::table('catalog.imports')->where('id', $failed)->update(['state' => 'FAILED', 'failure' => 'product 1: no.']);
        expect(app(ViewImportHandler::class)->handle(new ViewImport($failed))->failure)->toBe('product 1: no.');
    });

    it('is a Super Admin\'s, and the list of files too, newest first', function () {
        $older = Ix::uploadProducts([Ix::product('1'), Ix::product('2')]);
        DB::table('catalog.imports')->where('id', $older)->update(['created_at' => now()->subDay()]);
        $newer = Ix::uploadProducts([Ix::product('3')]);
        catalogPagesFill([['code' => '1', 'price' => 1]]);

        $list = app(ListImportsHandler::class)->handle(new ListImports);
        expect(array_map(static fn (ImportSummary $import): array => [$import->id, $import->count], $list->imports))->toBe([[$newer, 1], [$older, 2]])
            ->and($list->total)->toBe(2)
            ->and(app(ListImportsHandler::class)->handle(new ListImports(2, 1))->imports[0]->id)->toBe($older);

        Fx::actAsAdmin(['*'], CatalogPermissions::jobs());
        expect(fn () => app(ViewImportHandler::class)->handle(new ViewImport($newer)))->toThrow(Unauthorized::class)
            ->and(fn () => app(ListImportsHandler::class)->handle(new ListImports))->toThrow(Unauthorized::class);
    });
});

describe('a store file\'s page', function () {
    it('shows where each open item stands now: ready, not ready, archived, already on, or unknown', function () {
        $code = fn (array $ready): string => (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $ready = Px::ready();
        $on = Px::ready();
        $archived = Px::ready();
        $draft = Px::product();
        Px::variant($draft, '7700');
        Fx::asSystem(function () use ($on, $archived): void {
            app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $on['product'], true));
            app(ArchiveProductHandler::class)->handle(new ArchiveProduct($archived['product']));
        });
        $file = catalogPagesFill([
            ['code' => $code($ready), 'price' => 1], ['code' => '7700', 'price' => 2], ['code' => $code($archived), 'price' => 3],
            ['code' => $code($on), 'price' => 4], ['code' => '999999', 'price' => 5], ['code' => '1', 'price' => 6],
        ]);
        app(RemoveStoreFillItemsHandler::class)->handle(new RemoveStoreFillItems($file, [(string) DB::table('catalog.store_fill_items')->where('import_id', $file)->where('number', 6)->value('id')]));

        $view = app(ViewStoreFillHandler::class)->handle(new ViewStoreFill($file));

        expect(array_map(static fn (StoreFillItemView $item): array => [$item->number, $item->state, $item->standing], $view->items))->toBe([
            [1, 'OPEN', 'READY'], [2, 'OPEN', 'NOT_READY'], [3, 'OPEN', 'ARCHIVED'], [4, 'OPEN', 'ALREADY_ON'], [5, 'OPEN', 'UNKNOWN'], [6, 'REMOVED', null],
        ])
            ->and($view->items[1]->missing)->toBe(['description_ar', 'description_en', 'category', 'photos'])
            ->and($view->items[0]->productId)->toBe($ready['product'])
            ->and([$view->storeId, $view->pricesKept])->toBe([Fx::storeId('sa'), false]);
    });

    it('is the job of whoever fills that store, and its list holds only that store\'s files', function () {
        $sa = catalogPagesFill([['code' => '1', 'price' => 1]]);
        catalogPagesFill([['code' => '2', 'price' => 1]], 'eg');

        Fx::actAsAdmin(['sa'], [CatalogPermissions::LISTING_FILL]);
        expect(app(ViewStoreFillHandler::class)->handle(new ViewStoreFill($sa))->id)->toBe($sa)
            ->and(array_map(static fn (ImportSummary $file): string => $file->id, app(ListStoreFillsHandler::class)->handle(new ListStoreFills(Fx::storeId('sa')))->imports))->toBe([$sa])
            ->and(fn () => app(ListStoreFillsHandler::class)->handle(new ListStoreFills(Fx::storeId('eg'))))->toThrow(Unauthorized::class);
    });
});
