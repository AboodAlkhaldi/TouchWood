<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValues;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValuesHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWords;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWordsHandler;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotos;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotosHandler;
use Modules\Catalog\Application\Products\Readiness;
use Modules\Catalog\Application\Query\ListProducts\ListProducts;
use Modules\Catalog\Application\Query\ListProducts\ListProductsHandler;
use Modules\Catalog\Application\Query\Products\ProductFilter;
use Modules\Catalog\Application\Query\Products\ProductReaders;
use Modules\Catalog\Application\Query\Products\ProductRow;
use Modules\Catalog\Application\Query\ViewProduct\ViewProduct;
use Modules\Catalog\Application\Query\ViewProduct\ViewProductHandler;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The reads the products screens stand on (catalog.md §4.4 S8, S9): every product to whoever reads
| products in some store, each store's state only for the stores the reader covers (P4); the
| filters, the store's state, the pages; one product with its tabs, what it lacks to be made ready -
| the same answers as Readiness - and what the reader may do, as the handlers check it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string>
 */
function catalogProductReadsIds(ListProducts $query = new ListProducts): array
{
    return array_map(fn (ProductRow $row): string => $row->id, app(ListProductsHandler::class)->handle($query)->products);
}

function catalogProductReadsChoose(string $store, string $productId): void
{
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $productId, true)));
}

describe('the products list', function () {
    it('lists every product to whoever reads products in some store, newest first, and refuses anyone else before reading', function () {
        $older = Px::product('Hinge');
        $newer = Px::product('Rail');
        Cx::actAsStaffWith([P::PRODUCT_VIEW], ['eg']);

        expect(catalogProductReadsIds())->toBe([$newer, $older]);

        Cx::actAsStaffWith([P::BRAND_MANAGE]);
        $queries = Cx::recordQueries();

        expect(fn () => app(ListProductsHandler::class)->handle(new ListProducts))->toThrow(Unauthorized::class)
            ->and(array_filter($queries->getArrayCopy(), fn (array $query): bool => preg_match('/"?catalog"?\."?products/i', $query['sql']) === 1))->toBe([]);
    });

    it('shows where each product is on only among the stores the reader covers', function () {
        ['product' => $product] = Px::ready();
        catalogProductReadsChoose('sa', $product);
        catalogProductReadsChoose('eg', $product);

        Cx::actAsStaffWith([P::PRODUCT_VIEW], ['eg']);
        $row = app(ListProductsHandler::class)->handle(new ListProducts)->products[0];

        expect($row->onIn)->toBe([Fx::storeId('eg')]);

        Cx::actAsStaffWith([P::PRODUCT_VIEW]);

        expect(app(ListProductsHandler::class)->handle(new ListProducts)->products[0]->onIn)->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('eg')]);
    });

    it('finds a product by a name in either language, by a code typed in any digits, and narrows by stage, category and brand', function () {
        $brand = Px::brand('Hettich');
        $hinge = Px::product('Hinge', $brand);
        ['product' => $drawer, 'variants' => [$variant]] = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $variant)->value('code');
        $category = (string) DB::table('catalog.products')->where('id', $drawer)->value('category_id');
        $arabic = (string) DB::table('catalog.products')->where('id', $hinge)->value('name_ar');
        $indic = strtr($code, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
        Cx::actAsStaffWith([P::PRODUCT_VIEW]);

        expect(catalogProductReadsIds(new ListProducts(new ProductFilter(search: 'hinge'))))->toBe([$hinge])
            ->and(catalogProductReadsIds(new ListProducts(new ProductFilter(search: $arabic))))->toBe([$hinge])
            ->and(catalogProductReadsIds(new ListProducts(new ProductFilter(search: $indic))))->toBe([$drawer])
            ->and(catalogProductReadsIds(new ListProducts(new ProductFilter(search: '100%_'))))->toBe([])
            ->and(catalogProductReadsIds(new ListProducts(new ProductFilter(stage: 'READY'))))->toBe([$drawer])
            ->and(catalogProductReadsIds(new ListProducts(new ProductFilter(stage: 'SOLD'))))->toHaveCount(2)
            ->and(catalogProductReadsIds(new ListProducts(new ProductFilter(categoryId: $category))))->toBe([$drawer])
            ->and(catalogProductReadsIds(new ListProducts(new ProductFilter(brandId: $brand))))->toBe([$hinge]);
    });

    it('shows one store\'s state, and narrows by it', function () {
        ['product' => $on, 'variants' => $sizes] = Px::ready(['60 cm', '80 cm']);
        ['product' => $off] = Px::ready();
        ['product' => $away] = Px::ready();
        $never = Px::product('Draft');
        catalogProductReadsChoose('sa', $off);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $off, false)));
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $on, true, [$sizes[0]])));
        catalogProductReadsChoose('sa', $away);
        Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $away)));
        Cx::actAsStaffWith([P::PRODUCT_VIEW], ['sa']);

        $rows = collect(app(ListProductsHandler::class)->handle(new ListProducts(new ProductFilter(storeId: Fx::storeId('sa'))))->products);
        $row = fn (string $id): ProductRow => $rows->firstOrFail(fn (ProductRow $each): bool => $each->id === $id);

        expect([$row($on)->storeState, $row($on)->storeVariantsOn, $row($on)->variants])->toBe([ProductFilter::ON, 1, 2])
            ->and($row($off)->storeState)->toBe(ProductFilter::OFF)
            ->and($row($away)->storeState)->toBe(ProductFilter::NOT_AVAILABLE)
            ->and($row($never)->storeState)->toBe(ProductFilter::NOT_CHOSEN);

        foreach ([ProductFilter::ON => $on, ProductFilter::OFF => $off, ProductFilter::NOT_AVAILABLE => $away, ProductFilter::NOT_CHOSEN => $never] as $state => $product) {
            expect(catalogProductReadsIds(new ListProducts(new ProductFilter(storeId: Fx::storeId('sa'), storeState: $state))))->toBe([$product]);
        }

        expect(fn () => app(ListProductsHandler::class)->handle(new ListProducts(new ProductFilter(storeId: Fx::storeId('eg')))))->toThrow(Unauthorized::class);
    });

    it('reads a page at a time, the next one after the last row, in the same number of queries however many products', function () {
        $products = array_map(fn (int $n): string => Px::product("Item {$n}"), range(1, 3));
        Cx::actAsStaffWith([P::PRODUCT_VIEW]);
        app(ListProductsHandler::class)->handle(new ListProducts);

        $first = app(ListProductsHandler::class)->handle(new ListProducts(perPage: 2));
        $second = app(ListProductsHandler::class)->handle(new ListProducts(new ProductFilter(after: $first->products[1]->id), 2));

        expect(array_map(fn (ProductRow $row): string => $row->id, $first->products))->toBe([$products[2], $products[1]])
            ->and($first->more)->toBeTrue()
            ->and(array_map(fn (ProductRow $row): string => $row->id, $second->products))->toBe([$products[0]])
            ->and($second->more)->toBeFalse();

        $few = Cx::recordQueries();
        app(ListProductsHandler::class)->handle(new ListProducts);
        $fewCount = count($few);

        foreach (range(4, 9) as $n) {
            Px::ready();
        }

        $many = Cx::recordQueries();
        app(ListProductsHandler::class)->handle(new ListProducts);

        expect(count($many))->toBe($fewCount);
    });
});

describe('one product', function () {
    it('reads the product above its tabs: names, addresses, codes, its category\'s path, its gallery and where it is on', function () {
        $top = Px::category('Kitchens');
        ['product' => $product, 'variants' => [$variant]] = Px::ready(['60 cm'], Px::category('Drawers', $top));
        catalogProductReadsChoose('eg', $product);
        Cx::actAsStaffWith([P::PRODUCT_VIEW]);

        $view = app(ViewProductHandler::class)->handle(new ViewProduct($product));
        $core = $view->product;

        expect($core->stage)->toBe('READY')
            ->and($core->codes)->toBe([(string) DB::table('catalog.variants')->where('id', $variant)->value('code')])
            ->and(array_column($core->categoryPath, 'en'))->toHaveCount(2)
            ->and($core->categoryPath[0]['en'])->toStartWith('Kitchens')
            ->and($core->gallery)->toHaveCount(1)
            ->and($core->onIn)->toBe([Fx::storeId('eg')])
            ->and($core->slugEn)->not->toBeNull()
            ->and($view->missing)->toBe([])
            ->and($view->photoStates)->toBe([$core->gallery[0] => 'READY'])
            ->and($view->tab)->toBe(ViewProduct::DETAILS)
            ->and($view->options?->brands)->not->toBeEmpty()
            ->and($view->variants)->toBeNull();
    });

    it('says what a draft lacks to be made ready exactly as Readiness does', function () {
        $empty = Px::product(null);
        ['product' => $complete] = Px::ready();
        // Ready once, then its photo gone: Readiness and the page must agree on the photo too.
        $photoless = Px::ready()['product'];
        DB::table('catalog.product_photos')->where('product_id', $photoless)->delete();
        Cx::actAsStaffWith([P::PRODUCT_VIEW]);

        foreach ([$empty, $complete, $photoless] as $id) {
            $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No product.');

            expect(app(ViewProductHandler::class)->handle(new ViewProduct($id))->missing)->toBe(app(Readiness::class)->missing($product));
        }

        expect(app(ViewProductHandler::class)->handle(new ViewProduct($empty))->missing)->toBe(['name_en', 'description_ar', 'description_en', 'category', 'variants', 'photos']);
    });

    it('reads each tab\'s own data, and only it', function () {
        ['product' => $product, 'variants' => [$variant], 'width' => $width] = Px::ready(['60 cm']);
        ['product' => $other] = Px::ready();
        $material = Px::attribute('Material', 'INFORMATIONAL');
        $finish = Px::attribute('Finish', 'FILTERABLE');
        $matte = Px::value($finish, 'Matte');
        $eighty = Px::value($width, '80 cm');
        Fx::asSystem(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, ['مفصلة', 'hinge'])));
        Fx::asSystem(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'GOES_WITH', [$other])));
        Fx::asSystem(fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, [$matte])));
        $measured = Fx::asSystem(fn (): string => app(AddVariantHandler::class)->handle(new AddVariant(
            $product, '7001', [$width => $eighty], [$material => ['text_ar' => 'خشب', 'text_en' => 'Wood']], 450, 600, 450, 120, 20,
        )));
        $variantPhoto = Cx::media();
        Fx::asSystem(fn () => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($measured, [$variantPhoto])));
        Cx::actAsStaffWith([P::PRODUCT_VIEW]);
        $read = fn (string $tab) => app(ViewProductHandler::class)->handle(new ViewProduct($product, $tab));

        $variants = $read(ViewProduct::VARIANTS);
        $search = $read(ViewProduct::SEARCH);
        $related = $read(ViewProduct::RELATED);

        $only = $variants->variants ?? [];
        $relation = $related->related ?? [];

        $second = $only[1] ?? throw new LogicException('No second variant.');
        $galleryPhoto = $variants->product->gallery[0] ?? throw new LogicException('No gallery photo.');

        expect($only)->toHaveCount(2)
            ->and(array_map(fn ($each) => [$each->id, count($each->values)], $only))->toBe([[$variant, 1], [$measured, 1]])
            ->and([$second->code, $second->weightGrams, $second->lengthMm, $second->widthMm, $second->heightMm, $second->position])->toBe(['7001', 450, 600, 450, 120, 20])
            ->and(array_map(fn (array $detail): array => [$detail['attributeId'], $detail['textAr'], $detail['textEn'], $detail['number']], $second->details))->toBe([[$material, 'خشب', 'Wood', null]])
            // The Variants tab reads the gallery's photos and its variants' together, each with its sizes' state.
            ->and($second->photos)->toBe([$variantPhoto])
            ->and([$variants->photoStates[$galleryPhoto] ?? null, $variants->photoStates[$variantPhoto] ?? null])->toBe(['READY', 'READY'])
            ->and($search->photoStates)->toBe([$galleryPhoto => 'READY'])
            ->and($variants->attributes)->not->toBeEmpty()
            ->and($variants->options)->toBeNull()
            ->and($search->searchWords)->toBe(['مفصلة', 'hinge'])
            ->and($search->filterValueIds)->toBe([$matte])
            ->and(array_map(fn ($each) => [$each->productId, $each->kind], $relation))->toBe([[$other, 'GOES_WITH']])
            ->and($read('nonsense')->tab)->toBe(ViewProduct::DETAILS)
            ->and($read(ViewProduct::PHOTOS)->product->counts['goesWith'])->toBe(1);
    });

    it('says what the reader may do as the handlers check it: every store where it is on, or some store', function () {
        ['product' => $product] = Px::ready();
        $draft = Px::product('Draft');
        catalogProductReadsChoose('eg', $product);

        Cx::actAsStaffWith([P::PRODUCT_VIEW, P::PRODUCT_UPDATE, P::PRODUCT_PUBLISH, P::PRODUCT_ARCHIVE, P::VARIANT_CORRECT_CODE], ['sa']);
        $ready = app(ViewProductHandler::class)->handle(new ViewProduct($product));
        $draftView = app(ViewProductHandler::class)->handle(new ViewProduct($draft));

        // On in Egypt only: a job held in Saudi Arabia does not reach it; a draft is on nowhere.
        expect([$ready->mayUpdate, $ready->mayArchive, $ready->mayCorrectCode, $ready->mayPublish])->toBe([false, false, false, false])
            ->and([$draftView->mayUpdate, $draftView->mayPublish, $draftView->mayArchive, $draftView->mayCorrectCode])->toBe([true, true, true, false]);

        Cx::actAsStaffWith([P::PRODUCT_VIEW, P::PRODUCT_UPDATE, P::VARIANT_CORRECT_CODE], ['eg']);
        $here = app(ViewProductHandler::class)->handle(new ViewProduct($product));

        expect([$here->mayUpdate, $here->mayCorrectCode, $here->mayArchive])->toBe([true, true, false])
            // A store's id is the same id whatever the case it is written in.
            ->and(app(ProductReaders::class)->may(P::PRODUCT_UPDATE, [strtoupper(Fx::storeId('eg'))]))->toBeTrue()
            ->and(app(ProductReaders::class)->may(P::PRODUCT_UPDATE, [strtoupper(Fx::storeId('sa'))]))->toBeFalse();
    });

    it('answers a product that does not exist as not found, and refuses a reader of no products', function () {
        $product = Px::product('Hinge');
        Cx::actAsStaffWith([P::PRODUCT_VIEW]);

        expect(fn () => app(ViewProductHandler::class)->handle(new ViewProduct('01k0000000000000000000zzzz')))->toThrow(ProductNotFound::class)
            ->and(fn () => app(ViewProductHandler::class)->handle(new ViewProduct('not an id')))->toThrow(ProductNotFound::class);

        Cx::actAsStaffWith([P::PRODUCT_CREATE]);

        expect(fn () => app(ViewProductHandler::class)->handle(new ViewProduct($product)))->toThrow(Unauthorized::class);
    });
});
