<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\AddAttributeSet\AddAttributeSet;
use Modules\Catalog\Application\Command\AddAttributeSet\AddAttributeSetHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCode;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCodeHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\StoreVariantDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Catalog\Public\Enums\SaleMode;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Catalog's public contract (catalog.md §2.1, §8 #3, #19): ids in, DTOs out, for the modules above it
| — the code included, for staff and Sync, never for a shopper (amendment 5(d)) — and the server
| resolving the variant a shopper's picked values name.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function catalogApi(): CatalogApi
{
    return app(CatalogApi::class);
}

function catalogApiStore(string $code = 'sa'): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/**
 * A ready product whose variants are two finishes of two widths, each with its own code (amendment
 * 16(a)).
 *
 * @return array{product: string, width: string, finish: string, values: array<string, string>, variants: array<string, string>}
 */
function catalogApiProduct(): array
{
    $width = Px::attribute('Width');
    $finish = Px::attribute('Finish');
    $values = ['w60' => Px::value($width, '60 cm'), 'w80' => Px::value($width, '80 cm'), 'black' => Px::value($finish, 'Black'), 'white' => Px::value($finish, 'White')];
    $set = Fx::asSystem(fn (): string => app(AddAttributeSetHandler::class)->handle(new AddAttributeSet('مقاسات', 'Sizes', [$width, $finish])));
    $id = Px::product('Drawer');
    $category = Px::category();
    $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No product.');
    $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];
    Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, $product->name()->ar, $product->name()->en, $product->brandId(), descriptionAr: $text, descriptionEn: $text, categoryId: $category, attributeSetId: $set)));

    $variants = [
        '60-black' => Px::variant($id, '1304', [$width => $values['w60'], $finish => $values['black']]),
        '80-black' => Px::variant($id, '1307', [$width => $values['w80'], $finish => $values['black']]),
        '60-white' => Px::variant($id, '1305', [$width => $values['w60'], $finish => $values['white']]),
        '80-white' => Px::variant($id, '1306', [$width => $values['w80'], $finish => $values['white']]),
    ];

    Fx::asSystem(function () use ($id): void {
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [Cx::media()]));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
    });

    return ['product' => $id, 'width' => $width, 'finish' => $finish, 'values' => $values, 'variants' => $variants];
}

describe('variants and products', function () {
    it('answers a variant with its code, its product, its values in both languages and its physical facts', function () {
        $p = catalogApiProduct();
        DB::table('catalog.variants')->where('id', $p['variants']['80-white'])->update(['weight_grams' => 1200, 'length_mm' => 800]);

        $variant = catalogApi()->variant(strtoupper($p['variants']['80-white']));

        expect($variant)->toBeInstanceOf(VariantDto::class)
            ->and($variant?->id)->toBe($p['variants']['80-white'])
            ->and($variant?->productId)->toBe($p['product'])
            ->and($variant?->code)->toBe('1306')
            ->and(array_map(static fn ($value): string => $value->valueId, $variant->values ?? []))->toBe([$p['values']['w80'], $p['values']['white']])
            ->and($variant?->values[1]->attributeId)->toBe($p['finish'])
            ->and($variant?->values[1]->value->en)->toBe('White')
            ->and($variant?->values[1]->value->ar)->toBe((string) DB::table('catalog.attribute_values')->where('id', $p['values']['white'])->value('name_ar'))
            ->and($variant?->weightGrams)->toBe(1200)
            ->and($variant?->lengthMm)->toBe(800)
            ->and($variant?->widthMm)->toBeNull()
            ->and($variant?->isArchived)->toBeFalse()
            ->and(catalogApi()->variant('01k6abcdefghjkmnpqrstvwxyz'))->toBeNull()
            ->and(catalogApi()->variant('not-an-id'))->toBeNull();
    });

    it('answers the one variant carrying a code, archived or not', function () {
        $p = catalogApiProduct();

        expect(catalogApi()->variantByCode(' 1304 ')?->id)->toBe($p['variants']['60-black'])
            ->and(catalogApi()->variantByCode('1307')?->id)->toBe($p['variants']['80-black'])
            ->and(catalogApi()->variantByCode('9999'))->toBeNull()
            ->and(catalogApi()->variantByCode('13a4'))->toBeNull();

        // A code corrected away stays the product's (amendment 3(e)), but no variant carries it now.
        Fx::asSystem(function () use ($p): void {
            app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($p['variants']['80-white'], '1308'));
            app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($p['variants']['60-white']));
        });

        expect(catalogApi()->variantByCode('1306'))->toBeNull()
            ->and(catalogApi()->variantByCode('1308')?->id)->toBe($p['variants']['80-white'])
            ->and(catalogApi()->variantByCode('1305')?->isArchived)->toBeTrue();
    });

    it('answers a product, a draft with its Arabic name only included', function () {
        $p = Px::ready();
        $draft = Px::product(null);
        $product = catalogApi()->product($p['product']);

        expect($product?->id)->toBe($p['product'])
            ->and($product?->stage)->toBe(ProductStage::Ready)
            ->and($product?->nameEn)->toBe((string) DB::table('catalog.products')->where('id', $p['product'])->value('name_en'))
            ->and($product?->categoryId)->not->toBeNull()
            ->and(catalogApi()->product($draft)?->nameEn)->toBeNull()
            ->and(catalogApi()->product($draft)?->stage)->toBe(ProductStage::Draft)
            ->and(catalogApi()->product('01k6abcdefghjkmnpqrstvwxyz'))->toBeNull();
    });
});

describe('a variant in a store', function () {
    it('says a chosen variant is switched on, orderable without a price, selling retail with the product\'s limits', function () {
        $p = catalogApiProduct();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true, [$p['variants']['60-black']])));

        expect(catalogApi()->storeVariant(catalogApiStore(), $p['variants']['60-black']))->toEqual(new StoreVariantDto(
            Fx::storeId('sa'), $p['variants']['60-black'], $p['product'], true, true, false, [SaleMode::Retail], 1, null, null, null,
        ));

        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [$p['variants']['60-black'] => ['retail' => true, 'wholesale' => true]], 2, 50, 10, 500)));
        $terms = catalogApi()->storeVariant(catalogApiStore(), $p['variants']['60-black']);

        expect($terms?->saleModes)->toBe([SaleMode::Retail, SaleMode::Wholesale])
            ->and([$terms?->retailMinimum, $terms?->retailMaximum, $terms?->wholesaleMinimum, $terms?->wholesaleMaximum])->toBe([2, 50, 10, 500]);
    });

    it('says a variant cannot be ordered while it, or its product, is "Not available now" there', function (bool $wholeProduct) {
        $p = catalogApiProduct();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $p['product'], $wholeProduct ? null : $p['variants']['60-black'])));

        $marked = catalogApi()->storeVariant(catalogApiStore(), $p['variants']['60-black']);
        $other = catalogApi()->storeVariant(catalogApiStore(), $p['variants']['80-black']);

        expect($marked?->isActive)->toBeTrue()
            ->and($marked?->orderable)->toBeFalse()
            ->and($marked?->notAvailableNow)->toBeTrue()
            ->and($other?->orderable)->toBe(! $wholeProduct)
            ->and($other?->notAvailableNow)->toBe($wholeProduct)
            // Nothing in another store.
            ->and(catalogApi()->storeVariant(catalogApiStore('eg'), $p['variants']['60-black'])?->notAvailableNow)->toBeFalse();
    })->with(['the variant' => [false], 'the whole product' => [true]]);

    it('says a variant the store never chose, or an archived one, is off and cannot be ordered', function () {
        $p = catalogApiProduct();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($p['variants']['80-white'])));

        expect(catalogApi()->storeVariant(catalogApiStore('eg'), $p['variants']['60-black']))->toEqual(new StoreVariantDto(
            Fx::storeId('eg'), $p['variants']['60-black'], $p['product'], false, false, false, [], 1, null, null, null,
        ))
            ->and(catalogApi()->storeVariant(catalogApiStore(), $p['variants']['80-white'])?->orderable)->toBeFalse()
            ->and(catalogApi()->storeVariant(catalogApiStore(), $p['variants']['80-white'])?->isActive)->toBeFalse()
            ->and(catalogApi()->storeVariant(catalogApiStore(), '01k6abcdefghjkmnpqrstvwxyz'))->toBeNull();
    });

    it('says a variant switched off keeps the modes it had, for when it is switched on again', function () {
        $p = catalogApiProduct();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], false, [$p['variants']['60-black']])));
        $off = catalogApi()->storeVariant(catalogApiStore(), $p['variants']['60-black']);

        expect([$off?->isActive, $off?->orderable, $off?->saleModes])->toBe([false, false, [SaleMode::Retail]]);
    });

    it('says a variant cannot be ordered while its product is hidden with its category or brand — hidden is inactive', function (string $list) {
        $p = catalogApiProduct();
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
        $product = app(ProductRepository::class)->find($p['product']) ?? throw new LogicException('No product.');

        expect(catalogApi()->storeVariant(catalogApiStore(), $p['variants']['60-black'])?->orderable)->toBeTrue();

        Fx::asSystem(fn () => $list === 'category'
            ? app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory((string) $product->categoryId(), 'HIDE'))
            : app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($product->brandId(), 'HIDE')));
        $hidden = catalogApi()->storeVariant(catalogApiStore(), $p['variants']['60-black']);

        expect([$hidden?->isActive, $hidden?->orderable, $hidden?->notAvailableNow])->toBe([true, false, false]);
    })->with(['category', 'brand']);
});

describe('resolving the variant', function () {
    it('finds the variant the picked values name, in any order — the server\'s answer, never the browser\'s', function () {
        $p = catalogApiProduct();
        $v = $p['values'];

        expect(catalogApi()->resolveVariant($p['product'], [$v['white'], $v['w80']]))->toBe($p['variants']['80-white'])
            ->and(catalogApi()->resolveVariant(strtoupper($p['product']), [strtoupper($v['w60']), $v['black']]))->toBe($p['variants']['60-black'])
            ->and(catalogApi()->resolveVariant($p['product'], [$v['w60']]))->toBeNull()
            ->and(catalogApi()->resolveVariant($p['product'], [$v['w60'], $v['black'], $v['white']]))->toBeNull()
            ->and(catalogApi()->resolveVariant($p['product'], []))->toBeNull()
            ->and(catalogApi()->resolveVariant(Px::ready()['product'], [$v['w60'], $v['black']]))->toBeNull();
    });

    it('resolves a product with no attribute set — one variant, nothing to pick — from no values', function () {
        $id = Px::product('Bracket');
        $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No product.');
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Bracket']]]]];
        Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, $product->name()->ar, $product->name()->en, $product->brandId(), descriptionAr: $text, descriptionEn: $text, categoryId: Px::category())));
        $only = Px::variant($id, '4242');
        Fx::asSystem(function () use ($id): void {
            app(SetProductGalleryHandler::class)->handle(new SetProductGallery($id, [Cx::media()]));
            app(MarkProductReadyHandler::class)->handle(new MarkProductReady($id));
        });

        expect(catalogApi()->resolveVariant($id, []))->toBe($only)
            ->and(catalogApi()->resolveVariant($id, [Px::value(Px::attribute('Width'), '60 cm')]))->toBeNull()
            ->and(catalogApi()->resolveVariant(catalogApiProduct()['product'], []))->toBeNull();
    });

    it('never resolves an archived variant', function () {
        $p = catalogApiProduct();
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($p['variants']['60-white'])));

        expect(catalogApi()->resolveVariant($p['product'], [$p['values']['w60'], $p['values']['white']]))->toBeNull();
    });
});
