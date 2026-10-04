<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrand;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrandHandler;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategory;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategoryHandler;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabels;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabelsHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\ClearNotAvailableNow\ClearNotAvailableNow;
use Modules\Catalog\Application\Command\ClearNotAvailableNow\ClearNotAvailableNowHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\EditBrand\EditBrand;
use Modules\Catalog\Application\Command\EditBrand\EditBrandHandler;
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategory;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategoryHandler;
use Modules\Catalog\Application\Command\RebuildListing\RebuildListing;
use Modules\Catalog\Application\Command\RebuildListing\RebuildListingHandler;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValues;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValuesHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWords;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWordsHandler;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariant;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariantHandler;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Service\ArabicText;
use Modules\Catalog\Presentation\Console\RebuildListingCommand;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Public\Events\MediaVariantsReady;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

/*
| The listing (catalog.md §1.4, §5.4, §8 #10, #13): one row per store, language and product a shopper
| can find there, written inside the change that alters it, never holding a code (amendment 5(d));
| its card photo the first ready one; the repair writing the same rows from nothing.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Every row, as text, in key order: what a shopper's lists are read from.
 *
 * @return list<array<string, mixed>>
 */
function catalogRowsSnapshot(): array
{
    return array_values(array_map(static fn (stdClass $row): array => (array) $row, DB::select(
        'SELECT store_id, locale, product_id, name, slug, brand_id, brand_visible_by_default, category_id,'
        .' category_path::text AS category_path, in_category_pages, value_ids::text AS value_ids,'
        .' label_ids::text AS label_ids, card_media_id, card_photo::text AS card_photo, orderable, price_minor,'
        .' sales_rank, search_text, search_document::text AS search_document'
        .' FROM catalog.listing ORDER BY store_id, locale, product_id',
    )));
}

function catalogRowsOf(string $productId, string $store = 'sa', string $locale = 'ar'): ?stdClass
{
    $row = DB::table('catalog.listing')->where('store_id', Fx::storeId($store))->where('locale', $locale)->where('product_id', $productId)->first();

    return $row instanceof stdClass ? $row : null;
}

/**
 * @return list<string>
 */
function catalogRowsArray(mixed $value): array
{
    return array_values(array_filter(explode(',', trim((string) $value, '{}')), static fn (string $item): bool => $item !== ''));
}

/**
 * @param  list<string>|null  $variantIds
 */
function catalogRowsChoose(string $store, string $productId, bool $active = true, ?array $variantIds = null): void
{
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $productId, $active, $variantIds)));
}

/**
 * The product's details as they are, with these changed.
 *
 * @param  array<string, mixed>  $changes
 */
function catalogRowsEdit(string $productId, array $changes): void
{
    $product = app(ProductRepository::class)->find($productId) ?? throw new LogicException('No such product.');
    $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];

    Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails(...[
        'productId' => $productId,
        'nameAr' => $product->name()->ar,
        'nameEn' => $product->name()->en,
        'brandId' => $product->brandId(),
        'descriptionAr' => $text,
        'descriptionEn' => $text,
        'categoryId' => $product->categoryId(),
        'attributeSetId' => $product->attributeSetId(),
        ...$changes,
    ])));
}

function catalogRowsRebuild(): void
{
    Fx::asSystem(fn () => app(RebuildListingHandler::class)->handle(new RebuildListing));
}

function catalogRowsLabel(string $nameEn, int $position): string
{
    return Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('شارة', $nameEn, 'blue', $position)));
}

/**
 * The values a product's variants are made of, in the variants' order.
 *
 * @param  list<string>  $variantIds
 * @return list<string>
 */
function catalogRowsVariantValues(array $variantIds): array
{
    return array_map(static fn (string $variantId): string => (string) DB::table('catalog.variant_values')->where('variant_id', $variantId)->value('value_id'), $variantIds);
}

function catalogRowsPendingMedia(): string
{
    $id = Cx::media();
    DB::table('platform.media')->where('id', $id)->update(['variants_status' => 'PENDING', 'variants_generated_at' => null]);

    return $id;
}

describe('a row', function () {
    it('is written for each language of each store that chose the product — and never holds its code', function () {
        $p = Px::ready(['60 cm', '80 cm']);
        catalogRowsChoose('sa', $p['product']);
        catalogRowsChoose('eg', $p['product'], variantIds: [$p['variants'][0]]);
        $product = app(ProductRepository::class)->find($p['product']) ?? throw new LogicException('No product.');
        $gallery = app(ProductRepository::class)->gallery($p['product']);
        $values = catalogRowsVariantValues($p['variants']);
        $both = $values;
        sort($both);

        expect(DB::table('catalog.listing')->where('product_id', $p['product'])->count())->toBe(4)
            ->and(DB::table('catalog.listing')->where('store_id', Fx::storeId('ae'))->exists())->toBeFalse();

        foreach (['ar', 'en'] as $locale) {
            $row = catalogRowsOf($p['product'], 'sa', $locale) ?? throw new LogicException('No row.');
            $name = $locale === 'ar' ? $product->name()->ar : (string) $product->name()->en;
            $other = $locale === 'ar' ? (string) $product->name()->en : $product->name()->ar;

            expect($row->name)->toBe($name)
                ->and($row->slug)->toBe($locale === 'ar' ? $product->slugs()->ar->value : $product->slugs()->en?->value)
                ->and($row->brand_id)->toBe($product->brandId())
                ->and($row->category_id)->toBe($product->categoryId())
                ->and(catalogRowsArray($row->category_path))->toBe([$product->categoryId()])
                ->and($row->in_category_pages)->toBeTrue()
                ->and($row->brand_visible_by_default)->toBeTrue()
                ->and(catalogRowsArray($row->value_ids))->toBe($both)
                ->and(catalogRowsArray($row->label_ids))->toBe([])
                ->and($row->card_media_id)->toBe($gallery[0])
                ->and(array_keys((array) json_decode((string) $row->card_photo, true)))->toContain('card')
                ->and($row->orderable)->toBeTrue()
                ->and($row->price_minor)->toBeNull()
                ->and($row->sales_rank)->toBeNull()
                ->and($row->search_text)->toBe(ArabicText::normalize($name)."\n".ArabicText::normalize($other));
        }

        // Egypt sells one size: only its values are filters there.
        expect(catalogRowsArray(catalogRowsOf($p['product'], 'eg')?->value_ids))->toBe([$values[0]]);

        // Codes are for staff and admins only (amendment 5(d)): not a column, not a word searched.
        expect(Schema::getColumnListing('catalog.listing'))->not->toContain('code');

        foreach (DB::table('catalog.variants')->where('product_id', $p['product'])->pluck('code') as $code) {
            expect(DB::table('catalog.listing')->whereRaw("search_document @@ to_tsquery('simple', ?)", [$code])->exists())->toBeFalse()
                ->and(DB::table('catalog.listing')->where('search_text', 'like', "%{$code}%")->exists())->toBeFalse();
        }
    });

    it('is not written while a shopper cannot find the product there', function (Closure $hide) {
        $p = Px::ready(['60 cm', '80 cm']);
        catalogRowsChoose('sa', $p['product']);

        // The product is found before (lesson 68).
        expect(catalogRowsOf($p['product']))->not->toBeNull();

        Fx::asSystem(fn () => $hide($p));

        expect(DB::table('catalog.listing')->where('product_id', $p['product'])->exists())->toBeFalse();
    })->with([
        'every variant switched off there' => [fn (array $p) => catalogRowsChoose('sa', $p['product'], false)],
        'the product "Not available now" there' => [fn (array $p) => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $p['product']))],
        'every variant "Not available now" there' => [function (array $p) {
            foreach ($p['variants'] as $variantId) {
                app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $p['product'], $variantId));
            }
        }],
        'the product archived' => [fn (array $p) => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($p['product']))],
        'the only variant on sale archived' => [function (array $p) {
            catalogRowsChoose('sa', $p['product'], false, [$p['variants'][1]]);
            app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($p['variants'][0]));
        }],
        'hidden with its category' => [fn (array $p) => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory((string) app(ProductRepository::class)->find($p['product'])?->categoryId(), 'HIDE'))],
        'hidden with its brand' => [fn (array $p) => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand((string) app(ProductRepository::class)->find($p['product'])?->brandId(), 'HIDE'))],
    ]);

    it('keeps the product while one variant is still on sale, with that variant\'s values only', function () {
        $p = Px::ready(['60 cm', '80 cm']);
        catalogRowsChoose('sa', $p['product']);
        Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $p['product'], $p['variants'][0])));

        expect(catalogRowsArray(catalogRowsOf($p['product'])?->value_ids))->toBe([catalogRowsVariantValues($p['variants'])[1]]);
    });

    it('keeps a product left in an inactive category out of the category pages only', function () {
        $p = Px::ready();
        catalogRowsChoose('sa', $p['product']);
        $category = (string) app(ProductRepository::class)->find($p['product'])?->categoryId();

        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'LEAVE')));

        expect(catalogRowsOf($p['product'])?->in_category_pages)->toBeFalse();

        Fx::asSystem(fn () => app(ActivateCategoryHandler::class)->handle(new ActivateCategory($category)));

        expect(catalogRowsOf($p['product'])?->in_category_pages)->toBeTrue();
    });

    it('holds the ids from the top of the tree down to its category, so a parent lists everything below it', function () {
        $top = Px::category('Kitchens');
        $middle = Px::category('Drawers', $top);
        $lowest = Px::category('Runners', $middle);
        $p = Px::ready(categoryId: $lowest);
        catalogRowsChoose('sa', $p['product']);

        expect(catalogRowsArray(catalogRowsOf($p['product'])?->category_path))->toBe([$top, $middle, $lowest]);
    });

    it('holds the labels each store attached, in the list\'s order', function () {
        $p = Px::ready();
        catalogRowsChoose('sa', $p['product']);
        catalogRowsChoose('eg', $p['product']);
        $second = catalogRowsLabel('Second', 2);
        $first = catalogRowsLabel('First', 1);

        Fx::asSystem(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels(Fx::storeId('sa'), $p['product'], [$second, $first])));

        expect(catalogRowsArray(catalogRowsOf($p['product'])?->label_ids))->toBe([$first, $second])
            ->and(catalogRowsArray(catalogRowsOf($p['product'], 'eg')?->label_ids))->toBe([]);
    });

    it('searches both names, the search words and the names of its categories as search reads them — not the brand or the description', function () {
        $top = Px::category('Kitchens');
        $category = Px::category('Hinges', $top);
        $p = Px::ready(categoryId: $category);
        catalogRowsChoose('sa', $p['product']);
        $zebra = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Zebrawood']]]]];
        catalogRowsEdit($p['product'], ['nameAr' => 'مِفْصَلة أبواب', 'nameEn' => 'Door Hinge', 'descriptionAr' => $zebra, 'descriptionEn' => $zebra]);
        Fx::asSystem(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($p['product'], ['سكّة', 'Runner'])));
        $brand = (string) DB::table('catalog.brands')->where('id', app(ProductRepository::class)->find($p['product'])?->brandId())->value('name_en');
        $matches = static fn (string $locale, string $query): bool => DB::table('catalog.listing')->where('product_id', $p['product'])->where('locale', $locale)
            ->whereRaw("search_document @@ to_tsquery('simple', ?)", [$query])->exists();

        // The page's name first, the other a line below (amendment 5(f)).
        expect(catalogRowsOf($p['product'])?->search_text)->toBe("مفصله ابواب\ndoor hinge")
            ->and(catalogRowsOf($p['product'], 'sa', 'en')?->search_text)->toBe("door hinge\nمفصله ابواب");

        foreach (['ar', 'en'] as $locale) {
            expect($matches($locale, 'مفصله:A & ابواب:A & door:A & hinge:A'))->toBeTrue()
                ->and($matches($locale, 'سكه:B & runner:B'))->toBeTrue()
                // Its category and the one above it, in both languages (amendment 5(g)).
                ->and($matches($locale, 'hinges:C & kitchens:C & قسم:C'))->toBeTrue()
                ->and($matches($locale, strtolower(explode(' ', $brand)[0])))->toBeFalse()
                ->and($matches($locale, 'zebrawood'))->toBeFalse();
        }
    });
});

describe('kept current', function () {
    /*
    | §8 #13: each change writes the rows it alters in its own transaction — so what it left is exactly
    | what the repair writes from nothing, and a change that alters nothing a row holds is not here.
    */
    it('is written by the change itself, exactly as the repair writes it', function (Closure $prepare) {
        $p = Px::ready(['60 cm', '80 cm']);
        catalogRowsChoose('sa', $p['product']);
        catalogRowsChoose('eg', $p['product'], variantIds: [$p['variants'][0]]);
        $change = Fx::asSystem(static fn (): Closure => $prepare($p));
        $before = catalogRowsSnapshot();

        Fx::asSystem($change);
        $after = catalogRowsSnapshot();
        catalogRowsRebuild();

        expect($after)->not->toBe($before)
            ->and(catalogRowsSnapshot())->toBe($after);
    })->with([
        'editing its name' => [fn (array $p) => fn () => catalogRowsEdit($p['product'], ['nameEn' => 'Soft drawer'])],
        'moving it to another category' => [function (array $p) {
            $other = Px::category('Wardrobes');

            return fn () => catalogRowsEdit($p['product'], ['categoryId' => $other]);
        }],
        'giving it another brand' => [function (array $p) {
            $other = Px::brand('Hettich');

            return fn () => catalogRowsEdit($p['product'], ['brandId' => $other]);
        }],
        'changing a variant\'s values' => [function (array $p) {
            $value = Px::value($p['width'], '90 cm');
            $code = (string) DB::table('catalog.variants')->where('id', $p['variants'][0])->value('code');

            return fn () => app(UpdateVariantHandler::class)->handle(new UpdateVariant($p['variants'][0], $code, [$p['width'] => $value]));
        }],
        'putting another photo first' => [function (array $p) {
            $photo = Cx::media();
            $gallery = app(ProductRepository::class)->gallery($p['product']);

            return fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($p['product'], [$photo, ...$gallery]));
        }],
        'changing its search words' => [fn (array $p) => fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($p['product'], ['runner']))],
        'changing its filter values' => [function (array $p) {
            $use = Px::attribute('Use', 'FILTERABLE');
            $kitchen = Px::value($use, 'Kitchen');

            return fn () => app(SetFilterValuesHandler::class)->handle(new SetFilterValues($p['product'], [$kitchen]));
        }],
        'archiving it' => [fn (array $p) => fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($p['product']))],
        'archiving a variant' => [fn (array $p) => fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($p['variants'][1]))],
        'switching it off in a store' => [fn (array $p) => fn () => catalogRowsChoose('eg', $p['product'], false)],
        'choosing it in another store' => [fn (array $p) => fn () => catalogRowsChoose('ae', $p['product'])],
        'marking it "Not available now"' => [fn (array $p) => fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('eg'), $p['product']))],
        'clearing "Not available now"' => [function (array $p) {
            app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('eg'), $p['product']));

            return fn () => app(ClearNotAvailableNowHandler::class)->handle(new ClearNotAvailableNow(Fx::storeId('eg'), $p['product']));
        }],
        'attaching labels' => [function (array $p) {
            $label = catalogRowsLabel('New', 0);

            return fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels(Fx::storeId('sa'), $p['product'], [$label]));
        }],
        'deactivating its category, leaving it' => [fn (array $p) => fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory((string) app(ProductRepository::class)->find($p['product'])?->categoryId(), 'LEAVE'))],
        'deactivating its category, hiding it' => [fn (array $p) => fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory((string) app(ProductRepository::class)->find($p['product'])?->categoryId(), 'HIDE'))],
        'deactivating its category, moving it' => [function (array $p) {
            $other = Px::category('Wardrobes');

            return fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory((string) app(ProductRepository::class)->find($p['product'])?->categoryId(), 'MOVE', $other));
        }],
        'activating its category again' => [function (array $p) {
            $category = (string) app(ProductRepository::class)->find($p['product'])?->categoryId();
            app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'LEAVE'));

            return fn () => app(ActivateCategoryHandler::class)->handle(new ActivateCategory($category));
        }],
        'deactivating its brand, hiding it' => [fn (array $p) => fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand((string) app(ProductRepository::class)->find($p['product'])?->brandId(), 'HIDE'))],
        'deactivating its brand, moving it' => [function (array $p) {
            $other = Px::brand('Hettich');

            return fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand((string) app(ProductRepository::class)->find($p['product'])?->brandId(), 'MOVE', $other));
        }],
        'activating its brand again' => [function (array $p) {
            $brand = (string) app(ProductRepository::class)->find($p['product'])?->brandId();
            app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE'));

            return fn () => app(ActivateBrandHandler::class)->handle(new ActivateBrand($brand));
        }],
        'renaming its category' => [fn (array $p) => fn () => app(EditCategoryHandler::class)->handle(new EditCategory((string) app(ProductRepository::class)->find($p['product'])?->categoryId(), 'مفصلات', 'Hinges'))],
        'renaming the category above it' => [function (array $p) {
            $parent = Px::category('Kitchens');
            app(MoveCategoryHandler::class)->handle(new MoveCategory((string) app(ProductRepository::class)->find($p['product'])?->categoryId(), $parent));

            return fn () => app(EditCategoryHandler::class)->handle(new EditCategory($parent, 'مطابخ', 'Kitchen fittings'));
        }],
        'moving its category' => [function (array $p) {
            $parent = Px::category('Kitchens');

            return fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory((string) app(ProductRepository::class)->find($p['product'])?->categoryId(), $parent));
        }],
        'taking its brand out of default listings' => [function (array $p) {
            $brand = DB::table('catalog.brands')->where('id', app(ProductRepository::class)->find($p['product'])?->brandId())->first() ?? throw new LogicException('No brand.');

            return fn () => app(EditBrandHandler::class)->handle(new EditBrand((string) $brand->id, (string) $brand->name_ar, (string) $brand->name_en, (string) $brand->agency_type, false, (int) $brand->position));
        }],
        'deleting its card photo\'s file' => [function (array $p) {
            $second = Cx::media();
            $gallery = app(ProductRepository::class)->gallery($p['product']);
            app(SetProductGalleryHandler::class)->handle(new SetProductGallery($p['product'], [...$gallery, $second]));

            return fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($gallery[0]));
        }],
    ]);

    it('changes a row that stays in place, and leaves one that did not change unwritten', function () {
        $p = Px::ready();
        catalogRowsChoose('sa', $p['product']);
        // Every write to the rows from here on, as the database saw it: a row deleted and added again
        // would take a key lock on its card photo's file, which deleting that file holds (§5.4).
        DB::statement('CREATE TEMP TABLE catalog_listing_writes (op text)');
        DB::statement('CREATE FUNCTION pg_temp.catalog_listing_write() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN INSERT INTO catalog_listing_writes VALUES (TG_OP); RETURN NULL; END $$');
        DB::statement('CREATE TRIGGER catalog_listing_write AFTER INSERT OR UPDATE OR DELETE ON catalog.listing FOR EACH ROW EXECUTE FUNCTION pg_temp.catalog_listing_write()');

        Fx::asSystem(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($p['product'], ['runner'])));

        expect(DB::table('catalog_listing_writes')->pluck('op')->all())->toBe(['UPDATE', 'UPDATE']);

        DB::table('catalog_listing_writes')->delete();
        DB::transaction(fn () => app(ListingRows::class)->refresh([$p['product']]));

        expect(DB::table('catalog_listing_writes')->count())->toBe(0);
    });
});

describe('the card photo', function () {
    it('is the first photo whose sizes are ready, and becomes the first one once its sizes are', function () {
        $p = Px::ready();
        catalogRowsChoose('sa', $p['product']);
        $ready = app(ProductRepository::class)->gallery($p['product'])[0];
        $pending = catalogRowsPendingMedia();
        Fx::asSystem(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($p['product'], [$pending, $ready])));

        expect(catalogRowsOf($p['product'])?->card_media_id)->toBe($ready);

        DB::table('platform.media')->where('id', $pending)->update(['variants_status' => 'READY', 'variants_generated_at' => CarbonImmutable::now()]);
        event(new MediaVariantsReady((string) Str::uuid(), $pending, CarbonImmutable::now()));
        $once = catalogRowsSnapshot();

        // Heard twice, it writes the same rows (§9.3 #1).
        event(new MediaVariantsReady((string) Str::uuid(), $pending, CarbonImmutable::now()));

        expect(catalogRowsOf($p['product'])?->card_media_id)->toBe($pending)
            ->and(catalogRowsSnapshot())->toBe($once);
    });

    it('moves to the next photo when its file is deleted, so the delete is not refused', function () {
        $p = Px::ready();
        catalogRowsChoose('sa', $p['product']);
        $first = app(ProductRepository::class)->gallery($p['product'])[0];
        $second = Cx::media();
        Fx::asSystem(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($p['product'], [$first, $second])));

        Fx::asSystem(fn () => app(DeleteMediaHandler::class)->handle(new DeleteMedia($first)));

        expect(catalogRowsOf($p['product'])?->card_media_id)->toBe($second)
            ->and(DB::table('platform.media')->where('id', $first)->exists())->toBeFalse();
    });

    it('takes the products\' lock inside its own transaction, and nothing for a photo no product has', function () {
        $p = Px::ready();
        catalogRowsChoose('sa', $p['product']);
        $photo = app(ProductRepository::class)->gallery($p['product'])[0];
        $locks = Cx::recordLocks();

        event(new MediaVariantsReady((string) Str::uuid(), Cx::media(), CarbonImmutable::now()));

        expect($locks->getArrayCopy())->toBe([]);

        event(new MediaVariantsReady((string) Str::uuid(), $photo, CarbonImmutable::now()));

        expect($locks->getArrayCopy())->toBe([['key' => 'catalog:products', 'level' => 2]]);
    });
});

describe('the repair', function () {
    it('writes every row again from the products, putting back whatever drifted', function () {
        $listed = Px::ready(['60 cm', '80 cm']);
        catalogRowsChoose('sa', $listed['product']);
        catalogRowsChoose('eg', $listed['product'], variantIds: [$listed['variants'][1]]);
        $left = Px::ready();
        catalogRowsChoose('sa', $left['product']);
        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory((string) app(ProductRepository::class)->find($left['product'])?->categoryId(), 'LEAVE')));
        $archived = Px::ready();
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($archived['product'])));
        $written = catalogRowsSnapshot();

        DB::table('catalog.listing')->where('product_id', $listed['product'])->where('store_id', Fx::storeId('eg'))->where('locale', 'en')->delete();
        DB::table('catalog.listing')->where('product_id', $listed['product'])->where('store_id', Fx::storeId('sa'))->update(['name' => 'Stale']);
        DB::table('catalog.listing')->where('product_id', $left['product'])->update(['in_category_pages' => true]);
        DB::statement(
            'INSERT INTO catalog.listing SELECT store_id, locale, ?, name, slug, brand_id, brand_visible_by_default, category_id, in_category_pages,'
            .' card_media_id, card_photo, orderable, price_minor, sales_rank, search_text, category_path, value_ids, label_ids, search_document'
            .' FROM catalog.listing WHERE product_id = ? AND store_id = ? AND locale = ?',
            [$archived['product'], $left['product'], Fx::storeId('sa'), 'ar'],
        );

        expect(catalogRowsSnapshot())->not->toBe($written);

        $pending = artisan(RebuildListingCommand::NAME);

        if (! $pending instanceof PendingCommand) {
            throw new LogicException('Console output mocking is off, so the command cannot be checked.');
        }

        // Run now: a pending command otherwise runs only when it is let go, after the checks below.
        $pending->assertSuccessful()->run();

        expect(catalogRowsSnapshot())->toBe($written);
    });

    it('is the system\'s: no role runs it', function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE, CatalogPermissions::LISTING_CHOOSE]);

        expect(fn () => app(RebuildListingHandler::class)->handle(new RebuildListing))->toThrow(Unauthorized::class);
    });

    it('takes the products\' lock inside its own transaction', function () {
        $locks = Cx::recordLocks();

        catalogRowsRebuild();

        expect($locks->getArrayCopy())->toBe([['key' => 'catalog:products', 'level' => 2]]);
    });
});
