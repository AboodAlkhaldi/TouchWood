<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\Command\ArchiveImportedProducts\ArchiveImportedProducts;
use Modules\Catalog\Application\Command\ArchiveImportedProducts\ArchiveImportedProductsHandler;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodes;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodesHandler;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNames;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNamesHandler;
use Modules\Catalog\Application\Command\DeleteImportedProducts\DeleteImportedProducts;
use Modules\Catalog\Application\Command\DeleteImportedProducts\DeleteImportedProductsHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWords;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWordsHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Query\ViewImport\ViewImport;
use Modules\Catalog\Application\Query\ViewImport\ViewImportHandler;
use Modules\Catalog\Domain\Exception\CategoryNotLowest;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Exception\ImportUndecided;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Step 7, the reviews of the whole module (catalog.md amendment 11): a category picked for a product
| of the file has no sub-categories; the Super Admin's archive or delete on the import's page carried
| out whatever was done to a product since; the confirm catching a code a product not a draft keeps;
| a zip of too many entries refused.
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
 * An import of two whole products — names, a description, a lowest category, a photo — brought in as
 * drafts, their photos' sizes made.
 *
 * @return array{string, list<string>} the import, and the products it created
 */
function catalogPassBroughtIn(): array
{
    $category = (string) DB::table('catalog.categories')->where('id', Px::category('Hinges'))->value('name_en');
    $whole = ['description' => ['ar' => 'وصف', 'en' => 'About it'], 'category' => $category];
    $import = Ix::upload(Ix::zip([
        'products.json' => Ix::json([Ix::product('6100', [...$whole, 'photos' => ['6100.jpg']]), Ix::product('6200', [...$whole, 'photos' => ['6200.jpg']])]),
        '6100.jpg' => Ix::image('6100.jpg', 31),
        '6200.jpg' => Ix::image('6200.jpg', 32),
    ]));
    Ix::bringIn($import);
    DB::table('platform.media')->update(['variants_status' => 'READY', 'variants_generated_at' => now()]);

    return [$import, [Ix::broughtIn($import, 1), Ix::broughtIn($import, 2)]];
}

/** The first one made ready in the panel since, put on sale in a store, and named by another product. */
function catalogPassReadiedSince(string $productId): string
{
    $other = Px::ready();
    Fx::asSystem(function () use ($productId, $other): void {
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($productId));
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $productId, true));
        app(SetRelationsHandler::class)->handle(new SetRelations($other['product'], 'RELATED', [$productId]));
    });

    return $other['product'];
}

describe('a category picked for a name of the file (§1.5)', function () {
    it('has no sub-categories where a product sits, and may have them as a level above', function () {
        $cabinets = Px::category('Cabinets');
        $shelves = Px::category('Shelves', $cabinets);
        $import = Ix::uploadProducts([Ix::product('1', ['category' => 'Kitchens / Drawers'])]);
        $decide = fn (string $path, string $target) => app(DecideImportNamesHandler::class)->handle(new DecideImportNames($import, [['name_id' => Ix::nameId($import, 'CATEGORY', $path), 'decision' => 'EXISTING', 'target_id' => $target]]));

        expect(fn () => $decide('Kitchens / Drawers', $cabinets))->toThrow(CategoryNotLowest::class);

        $decide('Kitchens', $cabinets);
        $decide('Kitchens / Drawers', $shelves);

        expect(DB::table('catalog.import_names')->where('import_id', $import)->orderBy('written')->pluck('target_id')->all())->toBe([$cabinets, $shelves]);
    });
});

describe('archive and delete on the import\'s page, whatever was done since (11(c))', function () {
    it('deletes every one whole, one made ready and put on sale since taken off sale and unlinked first', function () {
        [$import, [$first, $second]] = catalogPassBroughtIn();
        $other = catalogPassReadiedSince($first);

        expect(app(DeleteImportedProductsHandler::class)->handle(new DeleteImportedProducts($import, null)))->toBe(2)
            ->and(DB::table('catalog.products')->whereIn('id', [$first, $second])->exists())->toBeFalse()
            ->and(DB::table('catalog.product_relations')->where('product_id', $other)->exists())->toBeFalse()
            ->and(DB::table('catalog.import_products')->where('import_id', $import)->orderBy('number')->pluck('state')->all())->toBe(['DELETED', 'DELETED'])
            // Off in its store first, as archiving does — that store's change audited there.
            ->and(DB::table('platform.audit_entries')->where('action', 'catalog.listing.chosen')->where('subject_id', $first)->where('store_id', Fx::storeId('sa'))->count())->toBe(2)
            ->and([Fx::audits('catalog.product.archived', $first), Fx::audits('catalog.product.deleted', $first), Fx::audits('catalog.product.deleted', $second)])->toBe([1, 1, 1])
            ->and(Fx::audits('catalog.product.relations_changed', $other))->toBe(2);
    });

    it('archives every one, one made ready and put on sale since switched off in its store', function () {
        [$import, [$first, $second]] = catalogPassBroughtIn();
        catalogPassReadiedSince($first);

        expect(app(ArchiveImportedProductsHandler::class)->handle(new ArchiveImportedProducts($import, null)))->toBe(2)
            ->and(DB::table('catalog.products')->whereIn('id', [$first, $second])->orderBy('id')->pluck('stage')->unique()->values()->all())->toBe(['ARCHIVED'])
            ->and(DB::table('catalog.store_variants')->where('product_id', $first)->where('is_active', true)->exists())->toBeFalse();
    });
});

describe('a product not a draft keeps its codes (11(b))', function () {
    it('is caught by the confirm, marked on the page, and let go by a skip', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        $sixty = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $width = (string) DB::table('catalog.attributes')->where('id', $ready['width'])->value('name_en');
        $set = (string) DB::table('catalog.attribute_sets')->where('id', DB::table('catalog.products')->where('id', $ready['product'])->value('attribute_set_id'))->value('name_en');
        // Its 80 cm variant given another code.
        $import = Ix::uploadProducts([Ix::product($sixty, ['attribute_set' => $set, 'variants' => [['code' => $sixty, 'values' => [$width => '60 cm']], ['code' => '7171', 'values' => [$width => '80 cm']]]])]);
        $decide = fn (string $decision) => app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => $decision]]));
        $decide('UPDATE');

        try {
            app(BringInImportHandler::class)->handle(new BringInImport($import));
        } catch (ImportUndecided $undecided) {
        }

        expect([$undecided->codeChanges ?? null, $undecided->codes ?? null])->toBe([1, 0])
            ->and(app(ViewImportHandler::class)->handle(new ViewImport($import))->products[0]->codeChange)->toBeTrue();

        $decide('SKIP');
        app(BringInImportHandler::class)->handle(new BringInImport($import));

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('BRINGING_IN');
    });

    it('lets a draft take the file\'s codes', function () {
        $draft = Px::ready(['60 cm', '80 cm']);
        DB::table('catalog.products')->where('id', $draft['product'])->update(['stage' => 'DRAFT']);
        $sixty = (string) DB::table('catalog.variants')->where('id', $draft['variants'][0])->value('code');
        $width = (string) DB::table('catalog.attributes')->where('id', $draft['width'])->value('name_en');
        $set = (string) DB::table('catalog.attribute_sets')->where('id', DB::table('catalog.products')->where('id', $draft['product'])->value('attribute_set_id'))->value('name_en');
        $import = Ix::uploadProducts([Ix::product($sixty, ['attribute_set' => $set, 'variants' => [['code' => $sixty, 'values' => [$width => '60 cm']], ['code' => '7171', 'values' => [$width => '80 cm']]]])]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));

        app(BringInImportHandler::class)->handle(new BringInImport($import));

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('BRINGING_IN');
    });
});

describe('a zip of too many entries (11(d))', function () {
    it('is refused before anything is read from it', function () {
        $path = (string) tempnam(sys_get_temp_dir(), 'tw-');
        Ix::remember($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('products.json', Ix::json([Ix::product('1')]));

        for ($index = 0; $index < 100_000; $index++) {
            $zip->addEmptyDir("d{$index}");
        }

        $zip->close();

        try {
            Ix::upload($path, 'spring.zip');
        } catch (ImportRefused $refused) {
        }

        expect($refused->problems ?? null)->toBe([['at' => 'file', 'problem' => 'at most 100,000 entries in the zip, files and folders']])
            ->and(DB::table('catalog.imports')->exists())->toBeFalse();
    });
});

describe('products left out at upload (11(a))', function () {
    it('take no part in a change, the confirm or bringing in, the rest of the file coming in', function () {
        $sizes = Px::ready(['60 cm', '80 cm']);
        $code = static fn (string $variantId): string => (string) DB::table('catalog.variants')->where('id', $variantId)->value('code');
        $import = Ix::uploadProducts([Ix::product($code($sizes['variants'][0])), Ix::product($code($sizes['variants'][1])), Ix::product('9100')]);
        $words = fn (?array $only) => app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, $only, ['slide'], 'ADD'));

        expect(fn () => $words([Ix::productId($import, 1)]))->toThrow(InvalidCatalogAttribute::class, 'left out at upload')
            ->and($words(null))->toBe(1);

        Ix::bringIn($import);

        expect(DB::table('catalog.import_products')->where('import_id', $import)->orderBy('number')->get(['state', 'edited', 'conflict_product_id'])->map(static fn (object $row): array => [$row->state, $row->edited === null, $row->conflict_product_id])->all())
            ->toBe([['REFUSED', true, null], ['REFUSED', true, null], ['IN', false, null]])
            ->and(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('IN')
            ->and(DB::table('catalog.variants')->where('product_id', $sizes['product'])->count())->toBe(2);
    });
});
