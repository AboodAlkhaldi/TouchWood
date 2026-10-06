<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabels;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabelsHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\EditBrand\EditBrand;
use Modules\Catalog\Application\Command\EditBrand\EditBrandHandler;
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Application\Command\EditLabel\EditLabel;
use Modules\Catalog\Application\Command\EditLabel\EditLabelHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\RankCategories\RankCategories;
use Modules\Catalog\Application\Command\RankCategories\RankCategoriesHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Query\Shop\BrandPage;
use Modules\Catalog\Application\Query\Shop\CardLabel;
use Modules\Catalog\Application\Query\Shop\CategoryPage;
use Modules\Catalog\Application\Query\Shop\MenuCategory;
use Modules\Catalog\Application\Query\Shop\Moved;
use Modules\Catalog\Application\Query\Shop\ProductCard;
use Modules\Catalog\Application\Query\Shop\ProductPage;
use Modules\Catalog\Application\Query\Shop\ShopCatalog;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What a shopper reads (catalog.md §1.4, §1.5, §1.10, §8 #10–#12; amendment 5(a), (h)): the menu
| each store shows by itself, a category's and a brand's pages a page at a time, a product's page —
| or the one "Not available now" page — and what it suggests. Never a code (amendment 5(d)).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function catalogShopStore(string $code): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/**
 * @param  list<string>|null  $variantIds
 */
function catalogShopChoose(string $store, string $productId, bool $active = true, ?array $variantIds = null): void
{
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $productId, $active, $variantIds)));
}

/**
 * A ready product in this category, on this brand if one is given, chosen in these stores.
 *
 * @param  list<string>  $stores
 */
function catalogShopProduct(string $categoryId, array $stores = ['sa'], ?string $brandId = null): string
{
    $id = Px::ready(categoryId: $categoryId)['product'];

    if ($brandId !== null) {
        $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No product.');
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];
        Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, $product->name()->ar, $product->name()->en, $brandId, descriptionAr: $text, descriptionEn: $text, categoryId: $categoryId, attributeSetId: $product->attributeSetId())));
    }

    foreach ($stores as $store) {
        catalogShopChoose($store, $id);
    }

    return $id;
}

/**
 * The menu as ids, each with its sub-categories.
 *
 * @param  list<MenuCategory>  $menu
 * @return array<string, mixed>
 */
function catalogShopTree(array $menu): array
{
    $tree = [];

    foreach ($menu as $category) {
        $tree[$category->id] = catalogShopTree($category->children);
    }

    return $tree;
}

function catalogShopSlug(string $kind, string $id, string $locale = 'en'): string
{
    return (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', $locale)->where('is_current', true)->value('slug');
}

/**
 * Every card of a category's page, page by page.
 *
 * @param  list<string>  $brandIds
 * @return list<string>
 */
function catalogShopAllCards(string $store, string $categoryId, int $limit, array $brandIds = []): array
{
    $ids = [];
    $after = null;

    do {
        $page = app(ShopCatalog::class)->category(catalogShopStore($store), 'en', catalogShopSlug('category', $categoryId), $brandIds, $after, $limit);

        if (! $page instanceof CategoryPage) {
            return $ids;
        }

        array_push($ids, ...array_map(static fn (ProductCard $card): string => $card->productId, $page->products->cards));
        $after = $page->products->next;
    } while ($after !== null);

    return $ids;
}

function catalogShopHideBrandFromDefaults(string $brandId): void
{
    $brand = DB::table('catalog.brands')->where('id', $brandId)->first() ?? throw new LogicException('No brand.');
    Fx::asSystem(fn () => app(EditBrandHandler::class)->handle(new EditBrand($brandId, (string) $brand->name_ar, (string) $brand->name_en, (string) $brand->agency_type, false, (int) $brand->position)));
}

describe('the menu', function () {
    it('shows a category by itself once the store lists something in it or below it, its parents with it', function () {
        $top = Px::category('Kitchens');
        $middle = Px::category('Drawers', $top);
        $lowest = Px::category('Runners', $middle);
        // A category no store lists anything in is in no menu.
        Px::category('Lighting');
        $product = catalogShopProduct($lowest);

        expect(catalogShopTree(app(ShopCatalog::class)->menu(catalogShopStore('sa'), 'en')))->toBe([$top => [$middle => [$lowest => []]]])
            ->and(app(ShopCatalog::class)->menu(catalogShopStore('eg'), 'en'))->toBe([]);

        catalogShopChoose('eg', $product);
        catalogShopChoose('sa', $product, false);

        expect(catalogShopTree(app(ShopCatalog::class)->menu(catalogShopStore('eg'), 'en')))->toBe([$top => [$middle => [$lowest => []]]])
            ->and(app(ShopCatalog::class)->menu(catalogShopStore('sa'), 'en'))->toBe([]);

        $menu = app(ShopCatalog::class)->menu(catalogShopStore('eg'), 'ar');

        expect($menu[0]->name)->toBe((string) DB::table('catalog.categories')->where('id', $top)->value('name_ar'))
            ->and($menu[0]->slug)->toBe(catalogShopSlug('category', $top, 'ar'));
    });

    it('orders a store\'s categories by its own order, and by the base store\'s until its admins place them', function () {
        $first = Px::category('Kitchens');
        $second = Px::category('Wardrobes');

        foreach ([$first, $second] as $category) {
            catalogShopProduct($category, ['sa', 'eg', 'ae']);
        }

        Fx::asSystem(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('sa'), [$first => 2, $second => 1])));
        Fx::asSystem(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('eg'), [$first => 1, $second => 2])));
        // A store opened later has no order of its own (amendment 2(b)).
        DB::table('catalog.store_category_ranks')->where('store_id', Fx::storeId('ae'))->delete();
        $order = static fn (string $store): array => array_map(static fn (MenuCategory $category): string => $category->id, app(ShopCatalog::class)->menu(catalogShopStore($store), 'en'));

        expect($order('sa'))->toBe([$second, $first])
            ->and($order('eg'))->toBe([$first, $second])
            ->and($order('ae'))->toBe([$second, $first]);
    });

    it('leaves out a category deactivated by hand, even with a product left in it — and a parent with nothing else', function () {
        $parent = Px::category('Kitchens');
        $category = Px::category('Hinges', $parent);
        catalogShopProduct($category);

        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'LEAVE')));

        expect(app(ShopCatalog::class)->menu(catalogShopStore('sa'), 'en'))->toBe([]);
    });

    it('shows a secondary brand\'s own category as any other, its sub-categories with it — the way in to its products', function () {
        $kitchens = Px::category('Kitchens');
        $tallsen = Px::category('Tallsen');
        $baskets = Px::category('Baskets', $tallsen);
        $brand = Px::brand('Tallsen');
        catalogShopHideBrandFromDefaults($brand);
        catalogShopProduct($kitchens);
        catalogShopProduct($baskets, brandId: $brand);
        Fx::asSystem(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('sa'), [$kitchens => 1, $tallsen => 2])));

        expect(catalogShopTree(app(ShopCatalog::class)->menu(catalogShopStore('sa'), 'en')))->toBe([$kitchens => [], $tallsen => [$baskets => []]]);
    });
});

describe('a category page', function () {
    it('lists everything in it and below it, best-selling first then newest, a page at a time', function () {
        $parent = Px::category('Kitchens');
        $left = Px::category('Drawers', $parent);
        $right = Px::category('Runners', $parent);
        $ids = [catalogShopProduct($left), catalogShopProduct($left), catalogShopProduct($right), catalogShopProduct($right), catalogShopProduct($left), catalogShopProduct($right)];
        // Sales pushes ranks from stage 6 (§2.2): three are ranked — two of them alike, the newer
        // first — and the rest follow, newest first.
        DB::table('catalog.listing')->where('product_id', $ids[3])->update(['sales_rank' => 1]);
        DB::table('catalog.listing')->whereIn('product_id', [$ids[0], $ids[5]])->update(['sales_rank' => 2]);
        $tied = [$ids[0], $ids[5]];
        rsort($tied);
        $unranked = [$ids[1], $ids[2], $ids[4]];
        rsort($unranked);

        expect(catalogShopAllCards('sa', $parent, 2))->toBe([$ids[3], ...$tied, ...$unranked])
            ->and(catalogShopAllCards('sa', $parent, 1))->toBe([$ids[3], ...$tied, ...$unranked])
            ->and(catalogShopAllCards('sa', $left, 1))->toBe(array_values(array_filter([$ids[0], ...$unranked], static fn (string $id): bool => in_array($id, [$ids[0], $ids[1], $ids[4]], true))));
    });

    it('keeps a product left in a sub-category that is off out of its parent\'s page', function () {
        $parent = Px::category('Kitchens');
        $kept = Px::category('Drawers', $parent);
        $off = Px::category('Runners', $parent);
        $shown = catalogShopProduct($kept);
        catalogShopProduct($off);

        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($off, 'LEAVE')));

        expect(catalogShopAllCards('sa', $parent, 10))->toBe([$shown]);
    });

    it('lists every brand\'s products, a secondary brand\'s too, the shopper\'s brand filter narrowing the page and never taking it away', function () {
        $category = Px::category('Baskets');
        $brand = Px::brand('Tallsen');
        catalogShopHideBrandFromDefaults($brand);
        $house = catalogShopProduct($category);
        $tallsen = catalogShopProduct($category, brandId: $brand);
        $both = [$house, $tallsen];
        rsort($both);
        $page = app(ShopCatalog::class)->category(catalogShopStore('sa'), 'en', catalogShopSlug('category', $category), [Px::brand('Hettich')]);

        expect(catalogShopAllCards('sa', $category, 10))->toBe($both)
            ->and(catalogShopAllCards('sa', $category, 10, [strtoupper($brand), 'not-a-brand']))->toBe([$tallsen])
            ->and($page)->toBeInstanceOf(CategoryPage::class)
            ->and($page instanceof CategoryPage ? $page->products->cards : null)->toBe([])
            ->and(fn () => app(ShopCatalog::class)->category(catalogShopStore('sa'), 'en', catalogShopSlug('category', $category), array_fill(0, ShopCatalog::BRANDS_MAX + 1, $brand)))
            ->toThrow(InvalidCatalogAttribute::class, 'at most '.ShopCatalog::BRANDS_MAX.' brands');
    });

    it('shows a card\'s labels in the list\'s order as it is now', function () {
        $category = Px::category('Baskets');
        $product = catalogShopProduct($category);
        $first = Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('أول', 'First', 'green', 1)));
        $second = Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('ثان', 'Second', 'blue', 2)));
        Fx::asSystem(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels(Fx::storeId('sa'), $product, [$first, $second])));
        $labels = static function () use ($category): array {
            $page = app(ShopCatalog::class)->category(catalogShopStore('sa'), 'en', catalogShopSlug('category', $category));

            return $page instanceof CategoryPage ? array_map(static fn (CardLabel $label): string => $label->name, $page->products->cards[0]->labels) : [];
        };

        expect($labels())->toBe(['First', 'Second']);

        Fx::asSystem(fn () => app(EditLabelHandler::class)->handle(new EditLabel($second, 'ثان', 'Second', 'blue', 0)));

        expect($labels())->toBe(['Second', 'First']);
    });

    it('answers an old slug with the current one, and nothing for a category off, unknown or listing nothing here', function () {
        $category = Px::category('Kitchens');
        catalogShopProduct($category);
        $old = catalogShopSlug('category', $category);
        Fx::asSystem(fn () => app(EditCategoryHandler::class)->handle(new EditCategory($category, 'مطابخ', 'Kitchen fittings', slugEn: 'kitchen-fittings')));
        $shop = app(ShopCatalog::class);

        expect($shop->category(catalogShopStore('sa'), 'en', $old))->toEqual(new Moved('kitchen-fittings'))
            ->and($shop->category(catalogShopStore('sa'), 'en', 'kitchen-fittings'))->toBeInstanceOf(CategoryPage::class)
            ->and($shop->category(catalogShopStore('sa'), 'en', 'no-such-category'))->toBeNull()
            ->and($shop->category(catalogShopStore('eg'), 'en', 'kitchen-fittings'))->toBeNull();

        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'LEAVE')));

        expect($shop->category(catalogShopStore('sa'), 'en', 'kitchen-fittings'))->toBeNull();
    });

    it('refuses a page cursor that is not one', function (string $after) {
        $category = Px::category('Kitchens');
        catalogShopProduct($category);

        expect(fn () => app(ShopCatalog::class)->category(catalogShopStore('sa'), 'en', catalogShopSlug('category', $category), [], $after))
            ->toThrow(InvalidCatalogAttribute::class, 'Invalid after: a page cursor');
    })->with(['no dot' => ['01k6abcdefghjkmnpqrstvwxyz'], 'not an id' => ['-.drawer'], 'a negative rank' => ['-1.01k6abcdefghjkmnpqrstvwxyz'], 'too big a rank' => ['99999999999.01k6abcdefghjkmnpqrstvwxyz']]);
});

describe('a brand page', function () {
    it('lists everything of the brand here, a product left in an inactive category included', function () {
        $brand = Px::brand('Hettich');
        $category = Px::category('Hinges');
        $listed = catalogShopProduct(Px::category('Drawers'), brandId: $brand);
        $left = catalogShopProduct($category, brandId: $brand);
        catalogShopProduct($category);
        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'LEAVE')));
        $expected = [$listed, $left];
        rsort($expected);

        $page = app(ShopCatalog::class)->brand(catalogShopStore('sa'), 'en', catalogShopSlug('brand', $brand));

        expect($page)->toBeInstanceOf(BrandPage::class)
            ->and($page instanceof BrandPage ? array_map(static fn (ProductCard $card): string => $card->productId, $page->products->cards) : null)->toBe($expected);

        $first = app(ShopCatalog::class)->brand(catalogShopStore('sa'), 'en', catalogShopSlug('brand', $brand), limit: 1);
        $next = $first instanceof BrandPage ? app(ShopCatalog::class)->brand(catalogShopStore('sa'), 'en', catalogShopSlug('brand', $brand), $first->products->next, 1) : null;

        expect($first instanceof BrandPage ? $first->products->cards[0]->productId : null)->toBe($expected[0])
            ->and($next instanceof BrandPage ? [$next->products->cards[0]->productId, $next->products->next] : null)->toBe([$expected[1], null]);
    });

    it('answers an old slug with the current one, and nothing for an unknown one', function () {
        $brand = Px::brand('Hettich');
        catalogShopProduct(Px::category('Drawers'), brandId: $brand);
        $old = catalogShopSlug('brand', $brand);
        $row = DB::table('catalog.brands')->where('id', $brand)->first() ?? throw new LogicException('No brand.');
        Fx::asSystem(fn () => app(EditBrandHandler::class)->handle(new EditBrand($brand, (string) $row->name_ar, 'Hettich fittings', (string) $row->agency_type, true, (int) $row->position, slugEn: 'hettich-fittings')));

        expect(app(ShopCatalog::class)->brand(catalogShopStore('sa'), 'en', $old))->toEqual(new Moved('hettich-fittings'))
            ->and(app(ShopCatalog::class)->brand(catalogShopStore('sa'), 'en', 'hettich-fittings'))->toBeInstanceOf(BrandPage::class)
            ->and(app(ShopCatalog::class)->brand(catalogShopStore('sa'), 'en', 'no-such-brand'))->toBeNull();
    });

    it('has none for an inactive brand', function () {
        $brand = Px::brand('Hettich');
        catalogShopProduct(Px::category('Drawers'), brandId: $brand);
        $slug = catalogShopSlug('brand', $brand);

        Fx::asSystem(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE')));

        expect(app(ShopCatalog::class)->brand(catalogShopStore('sa'), 'en', $slug))->toBeNull();
    });
});

describe('a product page', function () {
    it('is available while a shopper can find it here, with the variants on sale and the store\'s labels — and no code anywhere', function () {
        $p = Px::ready(['60 cm', '80 cm']);
        catalogShopChoose('sa', $p['product']);
        Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $p['product'], $p['variants'][0])));
        $later = Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('عرض', 'Offer', 'amber', 2)));
        $label = Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'New', 'green', 1)));
        Fx::asSystem(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels(Fx::storeId('sa'), $p['product'], [$later, $label])));
        // A photo whose sizes are not ready yet is not shown.
        $pending = Cx::media();
        DB::table('platform.media')->where('id', $pending)->update(['variants_status' => 'PENDING', 'variants_generated_at' => null]);
        Fx::asSystem(fn () => app(SetProductGalleryHandler::class)->handle(new SetProductGallery($p['product'], [$pending, ...app(ProductRepository::class)->gallery($p['product'])])));

        $page = app(ShopCatalog::class)->product(catalogShopStore('sa'), 'en', catalogShopSlug('product', $p['product']));

        if (! $page instanceof ProductPage) {
            throw new LogicException('No product page.');
        }

        expect($page->available)->toBeTrue()
            ->and($page->noindex())->toBeFalse()
            ->and(array_map(static fn ($variant): string => $variant->variantId, $page->variants))->toBe([$p['variants'][1]])
            ->and($page->variants[0]->values[0]->value)->toBe('80 cm')
            ->and($page->variants[0]->values[0]->attributeId)->toBe($p['width'])
            ->and(array_map(static fn (CardLabel $label): string => $label->name, $page->labels))->toBe(['New', 'Offer'])
            ->and($page->photos)->toHaveCount(1)
            ->and($page->description)->toHaveKey('blocks');

        $everything = json_encode($page, JSON_THROW_ON_ERROR);

        expect($everything)->not->toContain('"code"');

        foreach (DB::table('catalog.variants')->where('product_id', $p['product'])->pluck('code') as $code) {
            expect($everything)->not->toContain((string) $code);
        }
    });

    it('shows one "Not available now" page, kept from search engines, for a product that exists but cannot be ordered here', function (Closure $makeUnavailable) {
        $p = Px::ready();
        catalogShopChoose('sa', $p['product']);
        $slug = catalogShopSlug('product', $p['product']);

        Fx::asSystem(fn () => $makeUnavailable($p['product']));
        $page = app(ShopCatalog::class)->product(catalogShopStore('sa'), 'en', $slug);

        if (! $page instanceof ProductPage) {
            throw new LogicException('No product page.');
        }

        expect($page->available)->toBeFalse()
            ->and($page->noindex())->toBeTrue()
            ->and($page->name)->toBe((string) DB::table('catalog.products')->where('id', $p['product'])->value('name_en'))
            ->and($page->photos)->toHaveCount(1)
            ->and($page->description)->toHaveKey('blocks')
            ->and($page->variants)->toBe([])
            ->and($page->labels)->toBe([]);
    })->with([
        'not chosen in this store' => [fn (string $id) => catalogShopChoose('sa', $id, false)],
        '"Not available now" here' => [fn (string $id) => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $id))],
        'archived' => [fn (string $id) => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id))],
        'hidden with its category' => [fn (string $id) => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory((string) app(ProductRepository::class)->find($id)?->categoryId(), 'HIDE'))],
    ]);

    it('answers an old slug with the current one, and nothing for a slug that never existed or a product never made ready', function () {
        $p = Px::ready();
        catalogShopChoose('sa', $p['product']);
        $old = catalogShopSlug('product', $p['product']);
        $product = app(ProductRepository::class)->find($p['product']) ?? throw new LogicException('No product.');
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];
        Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($p['product'], $product->name()->ar, 'Soft drawer', $product->brandId(), slugEn: 'soft-drawer', descriptionAr: $text, descriptionEn: $text, categoryId: $product->categoryId(), attributeSetId: $product->attributeSetId())));
        $draft = Px::product('Draft');
        $archivedDraft = Px::product('Abandoned');
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($archivedDraft)));
        $shop = app(ShopCatalog::class);

        expect($shop->product(catalogShopStore('sa'), 'en', $old))->toEqual(new Moved('soft-drawer'))
            ->and($shop->product(catalogShopStore('sa'), 'en', 'soft-drawer'))->toBeInstanceOf(ProductPage::class)
            ->and($shop->product(catalogShopStore('sa'), 'en', 'never-a-product'))->toBeNull()
            ->and($shop->product(catalogShopStore('sa'), 'en', catalogShopSlug('product', $draft)))->toBeNull()
            ->and($shop->product(catalogShopStore('sa'), 'ar', catalogShopSlug('product', $archivedDraft, 'ar')))->toBeNull();
    });

    it('refuses a language the shop does not have', function () {
        expect(fn () => app(ShopCatalog::class)->product(catalogShopStore('sa'), 'fr', 'drawer'))->toThrow(InvalidCatalogAttribute::class, 'Invalid locale: ar or en');
    });
});

describe('suggestions', function () {
    it('fills "You may also like" from the same category, then the same brand, only when none was picked, and only with what this store lists', function () {
        $category = Px::category('Drawers');
        $brand = Px::brand('Hettich');
        $product = catalogShopProduct($category, brandId: $brand);
        $sameCategory = catalogShopProduct($category);
        $sameBrand = catalogShopProduct(Px::category('Hinges'), brandId: $brand);
        $notHere = catalogShopProduct($category, ['eg']);
        $cards = static fn (array $cards): array => array_map(static fn (ProductCard $card): string => $card->productId, $cards);
        $shop = app(ShopCatalog::class);

        expect($cards($shop->relations(catalogShopStore('sa'), 'en', $product)->mayAlsoLike))->toBe([$sameCategory, $sameBrand])
            ->and($cards($shop->relations(catalogShopStore('sa'), 'en', $product, 1)->mayAlsoLike))->toBe([$sameCategory]);

        Fx::asSystem(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', [$notHere, $sameBrand])));
        Fx::asSystem(fn () => app(SetRelationsHandler::class)->handle(new SetRelations($product, 'GOES_WITH', [$sameCategory, $notHere])));
        $relations = $shop->relations(catalogShopStore('sa'), 'en', $product);

        expect($cards($relations->mayAlsoLike))->toBe([$sameBrand])
            ->and($cards($relations->goesWith))->toBe([$sameCategory]);
    });

    it('suggests a secondary brand\'s products on its own products\' pages only', function () {
        $category = Px::category('Baskets');
        $tallsen = Px::brand('Tallsen');
        catalogShopHideBrandFromDefaults($tallsen);
        $house = catalogShopProduct($category);
        $basket = catalogShopProduct($category, brandId: $tallsen);
        $otherBasket = catalogShopProduct($category, brandId: $tallsen);
        $cards = static fn (array $cards): array => array_map(static fn (ProductCard $card): string => $card->productId, $cards);
        // Same category, newest first: the other basket, then the house product.
        $expected = [$otherBasket, $house];
        usort($expected, static fn (string $one, string $other): int => strcmp($other, $one));

        expect($cards(app(ShopCatalog::class)->relations(catalogShopStore('sa'), 'en', $house)->mayAlsoLike))->toBe([])
            ->and($cards(app(ShopCatalog::class)->relations(catalogShopStore('sa'), 'en', $basket)->mayAlsoLike))->toBe($expected);
    });
});
