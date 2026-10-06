<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProducts;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProductsHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodes;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodesHandler;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNames;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNamesHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWords;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWordsHandler;
use Modules\Catalog\Application\Query\ViewImport\ViewImport;
use Modules\Catalog\Application\Query\ViewImport\ViewImportHandler;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Exception\ImportUndecided;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The products file only brings products in (catalog.md amendment 9): it names no store, accepting
| puts nothing on sale, and a product it updates or replaces that is on sale is kept on sale or taken
| off, chosen every time. With the fixes after the second review: codes two catalog products hold, words
| added to a product it updates kept apart, a new category's taken address counted, and a job the queue
| gave up on waiting for the running one.
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

function saleCode(string $variantId): string
{
    return (string) DB::table('catalog.variants')->where('id', $variantId)->value('code');
}

/**
 * A ready product on sale in a store.
 *
 * @return array{product: string, variants: list<string>, width: string}
 */
function saleOnSale(string $store = 'sa'): array
{
    $ready = Px::ready();
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $ready['product'], true)));

    return $ready;
}

/**
 * The file's product for a ready one: its code, its set and its 60 cm value, so the update matches.
 *
 * @param  array{product: string, variants: list<string>, width: string}  $ready
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function saleFileProduct(array $ready, array $with = []): array
{
    $code = saleCode($ready['variants'][0]);
    $width = (string) DB::table('catalog.attributes')->where('id', $ready['width'])->value('name_en');
    $set = (string) DB::table('catalog.attribute_sets')->where('id', DB::table('catalog.products')->where('id', $ready['product'])->value('attribute_set_id'))->value('name_en');

    return Ix::product($code, ['attribute_set' => $set, 'variants' => [['code' => $code, 'values' => [$width => '60 cm']]], ...$with]);
}

/**
 * @return list<bool> the product's variants on in the store, as it is now
 */
function saleActive(string $productId, string $store = 'sa'): array
{
    return array_values(array_map('boolval', DB::table('catalog.store_variants')->where('product_id', $productId)->where('store_id', Fx::storeId($store))->pluck('is_active')->all()));
}

describe('a products file names no store (9(a))', function () {
    it('is refused when it does, saying where prices and stock come from', function () {
        try {
            Ix::uploadProducts([Ix::product('1', ['stores' => ['sa' => ['price' => 1]]])]);
        } catch (ImportRefused $refused) {
        }

        expect($refused->problems ?? null)->toBe([['at' => 'product 1 › stores', 'problem' => "not in a products file: each store's own file brings its prices and stock"]]);
    });
});

describe('a product on sale the file changes (9(c))', function () {
    it('waits for keep on sale or take off sale, every time, and is taken off sale in every store when chosen', function () {
        $taken = saleOnSale();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('eg'), $taken['product'], true)));
        $kept = saleOnSale();
        $import = Ix::uploadProducts([saleFileProduct($taken, ['name' => ['ar' => 'معدل']]), saleFileProduct($kept)]);
        $decide = fn (array $decisions) => app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, $decisions));
        $decide([['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE'], ['product_id' => Ix::productId($import, 2), 'decision' => 'UPDATE']]);

        expect(array_map(static fn ($product): array => [$product->onSale, $product->sale], app(ViewImportHandler::class)->handle(new ViewImport($import))->products))->toBe([[true, null], [true, null]]);

        try {
            app(BringInImportHandler::class)->handle(new BringInImport($import));
        } catch (ImportUndecided $undecided) {
        }

        expect($undecided->sales ?? null)->toBe(2)
            ->and(fn () => $decide([['product_id' => Ix::productId($import, 1), 'decision' => 'SKIP', 'sale' => 'KEEP']]))->toThrow(InvalidCatalogAttribute::class, 'only with UPDATE or REPLACE')
            ->and(fn () => $decide([['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE', 'sale' => 'MAYBE']]))->toThrow(InvalidCatalogAttribute::class, 'KEEP or TAKE_OFF');

        $decide([['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE', 'sale' => 'TAKE_OFF'], ['product_id' => Ix::productId($import, 2), 'decision' => 'UPDATE', 'sale' => 'KEEP']]);
        Ix::bringIn($import);

        expect([saleActive($taken['product']), saleActive($taken['product'], 'eg')])->toBe([[false], [false]])
            ->and(saleActive($kept['product']))->toBe([true])
            ->and(DB::table('catalog.listing')->where('product_id', $taken['product'])->exists())->toBeFalse()
            ->and(DB::table('catalog.listing')->where('product_id', $kept['product'])->exists())->toBeTrue()
            ->and(DB::table('catalog.products')->whereIn('id', [$taken['product'], $kept['product']])->pluck('stage')->unique()->values()->all())->toBe(['READY'])
            ->and(DB::table('catalog.products')->where('id', $taken['product'])->value('name_ar'))->toBe('معدل')
            ->and(DB::table('platform.audit_entries')->where('action', 'catalog.listing.chosen')->where('subject_id', $taken['product'])->whereIn('store_id', [Fx::storeId('sa'), Fx::storeId('eg')])->count())->toBe(4);
    });

    it('needs no choice for a product on sale nowhere, nor for one skipped', function () {
        $ready = Px::ready();
        $skipped = saleOnSale();
        $import = Ix::uploadProducts([saleFileProduct($ready), saleFileProduct($skipped)]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [
            ['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE'],
            ['product_id' => Ix::productId($import, 2), 'decision' => 'SKIP'],
        ]));

        expect(array_map(static fn ($product): bool => $product->onSale, app(ViewImportHandler::class)->handle(new ViewImport($import))->products))->toBe([false, true]);

        Ix::bringIn($import);

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('IN')
            ->and(saleActive($skipped['product']))->toBe([true]);
    });

    it('goes with its decision: a new decision without it, or one the catalog lets go, clears it', function () {
        $ready = saleOnSale();
        $draft = Px::product();
        Px::variant($draft, '4600');
        $import = Ix::uploadProducts([saleFileProduct($ready), Ix::product('4600')]);
        $decide = fn (int $number, array $decision) => app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, $number), ...$decision]]));
        $row = fn (int $number): array => (array) DB::table('catalog.import_products')->where('import_id', $import)->where('number', $number)->first(['decision', 'sale']);

        $decide(1, ['decision' => 'UPDATE', 'sale' => 'KEEP']);
        $decide(1, ['decision' => 'REPLACE']);
        $decide(2, ['decision' => 'UPDATE', 'sale' => 'KEEP']);
        // The draft holding its code goes: the decision about it goes too, and its sale with it.
        Fx::asSystem(fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($draft)));

        try {
            app(BringInImportHandler::class)->handle(new BringInImport($import));
        } catch (ImportUndecided) {
        }

        expect([$row(1), $row(2)])->toBe([['decision' => 'REPLACE', 'sale' => null], ['decision' => null, 'sale' => null]]);
    });

    it('fails the bringing in, to ask again, when the product went on sale after the confirm', function () {
        $ready = Px::ready();
        $import = Ix::uploadProducts([saleFileProduct($ready, ['name' => ['ar' => 'معدل']])]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));
        app(BringInImportHandler::class)->handle(new BringInImport($import));
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));

        Fx::asSystem(fn () => app(BringInImportProductsHandler::class)->handle(new BringInImportProducts($import)));

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('FAILED')
            ->and((string) DB::table('catalog.imports')->where('id', $import)->value('failure'))->toContain('went on sale after the confirm')
            ->and(DB::table('catalog.products')->where('id', $ready['product'])->value('name_ar'))->not->toBe('معدل')
            ->and(app(ViewImportHandler::class)->handle(new ViewImport($import))->products[0]->onSale)->toBeTrue()
            ->and(fn () => app(BringInImportHandler::class)->handle(new BringInImport($import)))->toThrow(ImportUndecided::class);
    });
});

describe('the confirm, again', function () {
    it('sends back to wait an update whose codes two catalog products now hold, which only a skip or new codes may decide', function () {
        $ready = Px::ready(['60 cm']);
        $code = saleCode($ready['variants'][0]);
        $width = (string) DB::table('catalog.attributes')->where('id', $ready['width'])->value('name_en');
        $set = (string) DB::table('catalog.attribute_sets')->where('id', DB::table('catalog.products')->where('id', $ready['product'])->value('attribute_set_id'))->value('name_en');
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
        $import = Ix::uploadProducts([Ix::product($code, ['attribute_set' => $set, 'variants' => [['code' => $code, 'values' => [$width => '60 cm']], ['code' => '7777', 'values' => [$width => '80 cm']]]])]);
        Ix::decideNames($import, ['80 cm' => Ix::create('80 سم', '80 cm')]);
        $decide = fn (string $decision) => app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => $decision, 'sale' => 'KEEP']]));
        $decide('UPDATE');
        Px::variant(Px::product(), '7777');

        expect(fn () => app(BringInImportHandler::class)->handle(new BringInImport($import)))->toThrow(ImportUndecided::class);
        expect((array) DB::table('catalog.import_products')->where('import_id', $import)->first(['decision', 'sale']))->toBe(['decision' => null, 'sale' => null])
            ->and(fn () => $decide('UPDATE'))->toThrow(InvalidCatalogAttribute::class, 'SKIP or RECODE: its codes belong to two catalog products');
    });

    it('counts a new category whose address another took since, keeping its decision', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['category' => 'Hinges'])]);
        $name = Ix::nameId($import, 'CATEGORY', 'Hinges');
        app(DecideImportNamesHandler::class)->handle(new DecideImportNames($import, [['name_id' => $name, ...Ix::create('مفصلات', 'Hinges')]]));
        // Another name, the same address: the name stays the import's to make, its address is taken.
        Fx::asSystem(fn () => app(AddCategoryHandler::class)->handle(new AddCategory('مفصلات أبواب', 'Door hinges', slugEn: 'hinges')));

        try {
            app(BringInImportHandler::class)->handle(new BringInImport($import));
        } catch (ImportUndecided $undecided) {
        }

        expect([$undecided->names ?? null, $undecided->addresses ?? null])->toBe([0, 1])
            ->and(DB::table('catalog.import_names')->where('id', $name)->value('decision'))->toBe('CREATE')
            ->and(app(ViewImportHandler::class)->handle(new ViewImport($import))->names[0]->addressTaken)->toBeTrue();
    });
});

describe('words added to a product the file updates', function () {
    it('are kept apart, and join the catalog\'s as they are when brought in — or the file\'s, once it replaces', function () {
        $updated = Px::ready();
        // A draft: replaced whole, it may lose what a ready product could not.
        $replaced = Px::product();
        Px::variant($replaced, '4500');
        DB::table('catalog.product_search_words')->insert([
            ['product_id' => $updated['product'], 'normalized' => 'runner', 'word' => 'runner', 'position' => 0],
            ['product_id' => $replaced, 'normalized' => 'old', 'word' => 'old', 'position' => 0],
        ]);
        $import = Ix::uploadProducts([saleFileProduct($updated), Ix::product('4500')]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [
            ['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE'],
            ['product_id' => Ix::productId($import, 2), 'decision' => 'UPDATE'],
        ]));

        app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, null, ['slide'], 'ADD'));
        $edited = json_decode((string) DB::table('catalog.import_products')->where('import_id', $import)->where('number', 1)->value('edited'), true);
        expect([$edited['search_words'], $edited['added_search_words']])->toBe([[], ['slide']]);

        // The catalog's product changes meanwhile; the second is now replaced, not updated.
        DB::table('catalog.product_search_words')->insert(['product_id' => $updated['product'], 'normalized' => 'rail', 'word' => 'rail', 'position' => 1]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 2), 'decision' => 'REPLACE']]));
        Ix::bringIn($import);

        expect(DB::table('catalog.product_search_words')->where('product_id', $updated['product'])->orderBy('position')->pluck('word')->all())->toBe(['runner', 'rail', 'slide'])
            ->and(DB::table('catalog.product_search_words')->where('product_id', $replaced)->pluck('word')->all())->toBe(['slide']);
    });
});

describe('a job the queue gave up on', function () {
    it('takes the products\' lock first, as the work does, before saying it stopped', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        app(BringInImportHandler::class)->handle(new BringInImport($import));
        $locks = Cx::recordLocks();

        app(BringInImportProductsHandler::class)->stopped($import);

        // Inside its own transaction (level 2 under RefreshDatabase's): outside one, the lock refuses.
        expect($locks->getArrayCopy()[0] ?? null)->toBe(['key' => 'catalog:products', 'level' => 2])
            ->and(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('FAILED');
    });
});
