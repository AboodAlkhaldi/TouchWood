<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProducts;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProductsHandler;
use Modules\Catalog\Application\Command\DiscardImport\DiscardImport;
use Modules\Catalog\Application\Command\DiscardImport\DiscardImportHandler;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFill;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFillHandler;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;

use function Pest\Laravel\seed;

/*
| Discarding a products file not brought in (catalog.md §1.12, amendment 10(b): "file is no longer at
| our db or sys"): a Super Admin's; one deciding, or whose bringing in failed, goes whole with its
| zip; one bringing its products in, or brought in, stays as their record.
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

function catalogDiscardImport(string $importId): void
{
    app(DiscardImportHandler::class)->handle(new DiscardImport($importId));
}

describe('discarding a products file', function () {
    it('takes it whole, its zip with it, while its products wait or once bringing them in failed', function () {
        $zip = Ix::upload(Ix::zip([
            'products.json' => Ix::json([Ix::product('6100', ['brand' => 'Blumm', 'photos' => ['6100.jpg']])]),
            '6100.jpg' => Ix::image('6100.jpg', 25),
        ]), 'spring.zip');
        $failed = Ix::uploadProducts([Ix::product('6200'), Ix::product('6300')]);
        DB::table('catalog.imports')->where('id', $failed)->update(['state' => 'FAILED', 'failure' => 'Product 1: no.']);
        $archive = (string) DB::table('catalog.imports')->where('id', $zip)->value('archive');
        Storage::disk('local')->assertExists($archive);

        catalogDiscardImport($zip);
        catalogDiscardImport($failed);

        Storage::disk('local')->assertMissing($archive);
        expect(DB::table('catalog.imports')->whereIn('id', [$zip, $failed])->exists())->toBeFalse()
            ->and(DB::table('catalog.import_products')->whereIn('import_id', [$zip, $failed])->exists())->toBeFalse()
            ->and(DB::table('catalog.import_names')->where('import_id', $zip)->exists())->toBeFalse()
            ->and(json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.import.discarded')->where('subject_id', $failed)->value('changes'), true))
            ->toEqual(['file_name' => ['products.json', null], 'state' => ['FAILED', null], 'products' => [2, null]])
            ->and(Fx::audits('catalog.import.discarded', $zip))->toBe(1);
    });

    it('leaves one bringing its products in, or brought in: its page is their record', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        app(BringInImportHandler::class)->handle(new BringInImport($import));

        expect(fn () => catalogDiscardImport($import))->toThrow(ImportClosed::class);

        Fx::asSystem(fn () => app(BringInImportProductsHandler::class)->handle(new BringInImportProducts($import)));

        expect(fn () => catalogDiscardImport($import))->toThrow(ImportClosed::class)
            ->and(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('IN');
    });

    it('is a Super Admin\'s, and a store\'s file is not one', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        $fill = app(UploadStoreFillHandler::class)->handle(new UploadStoreFill(Fx::storeId('sa'), Ix::temp(json_encode(['format' => 'touchwood-store-fill/1', 'items' => [['code' => '1', 'price' => 1]]], JSON_THROW_ON_ERROR)), 'prices.json'));

        expect(fn () => catalogDiscardImport($fill))->toThrow(ListItemNotFound::class);

        Fx::actAsAdmin(['*'], CatalogPermissions::jobs());
        expect(fn () => catalogDiscardImport($import))->toThrow(Unauthorized::class)
            ->and(DB::table('catalog.imports')->whereIn('id', [$import, $fill])->count())->toBe(2);
    });
});
