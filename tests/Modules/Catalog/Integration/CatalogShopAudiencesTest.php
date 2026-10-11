<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategory;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategoryHandler;
use Modules\Catalog\Application\Command\OrderVariants\OrderVariants;
use Modules\Catalog\Application\Command\OrderVariants\OrderVariantsHandler;
use Modules\Catalog\Application\Command\RebuildListing\RebuildListing;
use Modules\Catalog\Application\Command\RebuildListing\RebuildListingHandler;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiences;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiencesHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Application\Query\Shop\BrandPage;
use Modules\Catalog\Application\Query\Shop\CategoryPage;
use Modules\Catalog\Application\Query\Shop\MenuCategory;
use Modules\Catalog\Application\Query\Shop\ProductCard;
use Modules\Catalog\Application\Query\Shop\ProductPage;
use Modules\Catalog\Application\Query\Shop\ShopCatalog;
use Modules\Catalog\Application\Query\Shop\ShopSearch;
use Modules\Catalog\Application\Query\Shop\ShopVariant;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Individuals and companies (catalog.md §1.13, amendment 14, §8 #32–#34; P24, P25): two switches per
| category per store, a parent's reaching everything under it, a sub-category added or moved taking
| its new parent's; the listing saying who sees each product and each side's card; and the shop's
| reads answering for the viewer — an individual, a company, or a guest who sees what either sees.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function catalogAudiencesStore(string $code): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/**
 * Switches a category in a store as the system, the way an admin's press does.
 */
function catalogAudiencesSwitch(string $store, string $categoryId, ?bool $individuals = null, ?bool $companies = null): void
{
    Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId($store), $categoryId, $individuals, $companies)));
}

/**
 * A category's own row in a store: [individuals, companies], or null when nobody switched it there.
 *
 * @return array{bool, bool}|null
 */
function catalogAudiencesRowOf(string $store, string $categoryId): ?array
{
    $row = DB::table('catalog.store_category_audiences')->where('store_id', Fx::storeId($store))->where('category_id', $categoryId)->first();

    return $row instanceof stdClass ? [(bool) $row->for_individuals, (bool) $row->for_companies] : null;
}

/**
 * A ready product in this category, with one variant per size, chosen whole in these stores.
 *
 * @param  list<string>  $sizes
 * @param  list<string>  $stores
 * @return array{product: string, variants: list<string>, width: string}
 */
function catalogAudiencesProduct(string $categoryId, array $sizes = ['60 cm'], array $stores = ['sa']): array
{
    $p = Px::ready($sizes, $categoryId);

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $p['product'], true)));
    }

    return $p;
}

/**
 * How each of these variants is sold in the store: 'R' retail, 'W' wholesale, 'RW' both.
 *
 * @param  array<string, string>  $modes  variant id => R, W or RW
 */
function catalogAudiencesSell(string $store, string $productId, array $modes): void
{
    $sent = array_map(static fn (string $mode): array => ['retail' => str_contains($mode, 'R'), 'wholesale' => str_contains($mode, 'W')], $modes);

    Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId($store), $productId, $sent, wholesaleMinimum: 10)));
}

/**
 * The listing row's audience columns for a product in a store (both languages agree; English read).
 *
 * @return array<string, mixed>|null
 */
function catalogAudiencesListing(string $productId, string $store = 'sa'): ?array
{
    $row = DB::table('catalog.listing')->where('store_id', Fx::storeId($store))->where('locale', 'en')->where('product_id', $productId)
        ->first(['for_individuals', 'for_companies', 'sells_retail', 'sells_wholesale', 'card_variant_id', 'retail_card_variant_id']);

    return $row instanceof stdClass ? (array) $row : null;
}

/**
 * Every listing row, in key order, every column as text: what a shopper's lists are read from.
 *
 * @return list<array<string, mixed>>
 */
function catalogAudiencesSnapshot(): array
{
    return array_values(array_map(static fn (stdClass $row): array => (array) $row, DB::select(
        'SELECT store_id, locale, product_id, category_path::text AS category_path, value_ids::text AS value_ids,'
        .' for_individuals, for_companies, sells_retail, sells_wholesale, card_variant_id, retail_card_variant_id,'
        .' name, slug, in_category_pages, orderable FROM catalog.listing ORDER BY store_id, locale, product_id',
    )));
}

/**
 * The menu's categories, every level, as ids.
 *
 * @param  list<MenuCategory>  $menu
 * @return list<string>
 */
function catalogAudiencesMenuIds(array $menu): array
{
    $ids = [];

    foreach ($menu as $category) {
        $ids[] = $category->id;
        array_push($ids, ...catalogAudiencesMenuIds($category->children));
    }

    return $ids;
}

function catalogAudiencesSlug(string $kind, string $id): string
{
    return (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', 'en')->where('is_current', true)->value('slug');
}

/**
 * @param  list<ProductCard>  $cards
 * @return list<string>
 */
function catalogAudiencesIds(array $cards): array
{
    return array_map(static fn (ProductCard $card): string => $card->productId, $cards);
}

/**
 * What a viewer is shown in a store: the menu, a category's page, a brand's page, the search and the
 * suggestions, as product and category ids.
 *
 * @return array{menu: list<string>, category: list<string>|null, brand: list<string>, search: list<string>, suggest: list<string>}
 */
function catalogAudiencesSeen(ShopperKind $viewer, string $categoryId, string $brandId, string $store = 'sa', string $typed = 'Drawer'): array
{
    $shop = app(ShopCatalog::class);
    $page = $shop->category(catalogAudiencesStore($store), 'en', $viewer, catalogAudiencesSlug('category', $categoryId));
    $brand = $shop->brand(catalogAudiencesStore($store), 'en', $viewer, catalogAudiencesSlug('brand', $brandId));

    return [
        'menu' => catalogAudiencesMenuIds($shop->menu(catalogAudiencesStore($store), 'en', $viewer)),
        'category' => $page instanceof CategoryPage ? catalogAudiencesIds($page->products->cards) : null,
        'brand' => $brand instanceof BrandPage ? catalogAudiencesIds($brand->products->cards) : [],
        'search' => catalogAudiencesIds(app(ShopSearch::class)->results(catalogAudiencesStore($store), 'en', $viewer, $typed)->cards),
        'suggest' => catalogAudiencesIds(app(ShopSearch::class)->suggest(catalogAudiencesStore($store), 'en', $viewer, $typed)),
    ];
}

function catalogAudiencesPage(string $productId, ShopperKind $viewer, string $store = 'sa'): ProductPage
{
    $page = app(ShopCatalog::class)->product(catalogAudiencesStore($store), 'en', $viewer, catalogAudiencesSlug('product', $productId));

    return $page instanceof ProductPage ? $page : throw new LogicException('No product page.');
}

/**
 * @return list<string>
 */
function catalogAudiencesVariantsShown(string $productId, ShopperKind $viewer): array
{
    return array_map(static fn (ShopVariant $variant): string => $variant->variantId, catalogAudiencesPage($productId, $viewer)->variants);
}

function catalogAudiencesBrandOf(string $productId): string
{
    return (string) DB::table('catalog.products')->where('id', $productId)->value('brand_id');
}

describe('who may switch', function () {
    it('is an admin role\'s job in that store, and in that store only', function () {
        $category = Px::category('Kitchens');
        Fx::actAsAdmin(['sa'], [CatalogPermissions::CATEGORY_AUDIENCE]);

        app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $category, false));

        expect(catalogAudiencesRowOf('sa', $category))->toBe([false, true])
            ->and(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('eg'), $category, false)))->toThrow(Unauthorized::class)
            ->and(catalogAudiencesRowOf('eg', $category))->toBeNull();

        // Ordering the menu, or managing the tree with All stores, is not choosing who sees it.
        Fx::actAsAdmin(['*'], [CatalogPermissions::CATEGORY_RANK, CatalogPermissions::CATEGORY_MANAGE]);

        expect(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $category, true)))->toThrow(Unauthorized::class)
            ->and(catalogAudiencesRowOf('sa', $category))->toBe([false, true]);
    });

    it('refuses a store id that is not one as the permission would, a store that is no store, an unknown category and no switch at all', function () {
        $category = Px::category('Kitchens');
        Fx::actAsAdmin(['*'], [CatalogPermissions::CATEGORY_AUDIENCE]);
        $handler = app(SetCategoryAudiencesHandler::class);

        expect(fn () => $handler->handle(new SetCategoryAudiences('not-a-store', $category, false)))->toThrow(Unauthorized::class)
            ->and(fn () => $handler->handle(new SetCategoryAudiences('01j8z3k4m5n6p7q8r9s0t1v2w3', $category, false)))->toThrow(InvalidCatalogAttribute::class, 'Invalid store')
            ->and(fn () => $handler->handle(new SetCategoryAudiences(Fx::storeId('sa'), '01j8z3k4m5n6p7q8r9s0t1v2w3', false)))->toThrow(CategoryNotFound::class)
            ->and(fn () => $handler->handle(new SetCategoryAudiences(Fx::storeId('sa'), $category)))->toThrow(InvalidCatalogAttribute::class, 'Invalid audience')
            ->and(DB::table('catalog.store_category_audiences')->count())->toBe(0);
    });

    it('prepares a store that is off, as its menu order is', function () {
        $category = Px::category('Kitchens');
        // Its id taken while it is on: an off store's code finds no store (lesson 187).
        $ae = Fx::storeId('ae');
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));

        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences($ae, $category, null, false)));

        expect(DB::table('catalog.store_category_audiences')->where('store_id', $ae)->where('category_id', $category)->first(['for_individuals', 'for_companies']))
            ->toEqual((object) ['for_individuals' => true, 'for_companies' => false]);
    });

    it('takes the products\' lock, then the categories\', first thing inside its own transaction, reading nothing of the catalog before', function () {
        $category = Px::category('Kitchens');
        Fx::actAsAdmin(['sa'], [CatalogPermissions::CATEGORY_AUDIENCE]);
        $queries = Cx::recordQueries();

        app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $category, false));

        $inside = array_values(array_filter((array) $queries, static fn (array $query): bool => $query['level'] >= 2));
        $before = array_values(array_filter((array) $queries, static fn (array $query): bool => $query['level'] < 2 && str_contains($query['sql'], '"catalog".')));

        expect($before)->toBe([])
            ->and($inside[0]['sql'])->toContain('pg_advisory_xact_lock')
            ->and($inside[0]['bindings'])->toBe(['catalog:products'])
            ->and($inside[1]['sql'])->toContain('pg_advisory_xact_lock')
            ->and($inside[1]['bindings'])->toBe(['catalog:categories']);
    });
});

describe('the switches', function () {
    it('shows a category nobody switched to both kinds of account, in every store', function () {
        $category = Px::category('Kitchens');
        $p = catalogAudiencesProduct($category, stores: ['sa', 'eg', 'ae']);

        expect(DB::table('catalog.store_category_audiences')->count())->toBe(0);

        foreach (['sa', 'eg', 'ae'] as $store) {
            expect(catalogAudiencesListing($p['product'], $store))->toMatchArray(['for_individuals' => true, 'for_companies' => true]);
        }
    });

    it('shows everything to both in a store opened later, whatever the other stores switched off (§8 #32)', function () {
        $top = Px::category('Kitchens');
        $category = Px::category('Drawers', $top);
        $p = catalogAudiencesProduct($category, stores: ['sa', 'eg']);
        catalogAudiencesSwitch('sa', $top, false, false);
        catalogAudiencesSwitch('eg', $top, false, false);

        // A store has a currency of its own (platform.md §9.7 #3): Kuwait's is made with it. It opens
        // off - its id read from its row, as an off store's code finds no store (lesson 187).
        Fx::asSystem(fn () => app(CreateStoreHandler::class)->handle(new CreateStore('kw', 'الكويت', 'Kuwait', 'KW', '', 1500, 'Asia/Kuwait', 4, new CreateCurrency('KWD', 3, 'دينار كويتي', 'Kuwaiti dinar', 'د.ك', 'KWD', null))));
        $kw = (string) DB::table('platform.stores')->where('code', 'kw')->value('id');
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore($kw, $p['product'], true)));
        $row = DB::table('catalog.listing')->where('store_id', $kw)->where('locale', 'en')->where('product_id', $p['product'])->first(['for_individuals', 'for_companies']);

        expect(DB::table('catalog.store_category_audiences')->where('store_id', $kw)->count())->toBe(0)
            ->and($row)->toEqual((object) ['for_individuals' => true, 'for_companies' => true])
            ->and(catalogAudiencesMenuIds(app(ShopCatalog::class)->menu(StoreId::fromString($kw), 'en', ShopperKind::Individual)))->toBe([$top, $category])
            ->and(catalogAudiencesMenuIds(app(ShopCatalog::class)->menu(catalogAudiencesStore('sa'), 'en', ShopperKind::Individual)))->toBe([]);
    });

    it('switches everything under a parent the same way, the other switch left as each has it; a sub-category may then change alone; another store untouched', function () {
        $top = Px::category('Kitchens');
        $middle = Px::category('Drawers', $top);
        $lowest = Px::category('Runners', $middle);
        $other = Px::category('Wardrobes');
        $p = catalogAudiencesProduct($lowest, stores: ['sa', 'eg']);

        catalogAudiencesSwitch('sa', $lowest, null, false);
        catalogAudiencesSwitch('sa', $top, false);

        expect(catalogAudiencesRowOf('sa', $top))->toBe([false, true])
            ->and(catalogAudiencesRowOf('sa', $middle))->toBe([false, true])
            // Its own Companies switch, set before, stays: only the switch flipped reaches below.
            ->and(catalogAudiencesRowOf('sa', $lowest))->toBe([false, false])
            ->and(catalogAudiencesRowOf('sa', $other))->toBeNull()
            ->and(catalogAudiencesRowOf('eg', $lowest))->toBeNull()
            ->and(catalogAudiencesListing($p['product']))->toMatchArray(['for_individuals' => false, 'for_companies' => false])
            ->and(catalogAudiencesListing($p['product'], 'eg'))->toMatchArray(['for_individuals' => true, 'for_companies' => true]);

        // A sub-category switched on its own.
        catalogAudiencesSwitch('sa', $lowest, true, true);

        expect(catalogAudiencesRowOf('sa', $middle))->toBe([false, true])
            ->and(catalogAudiencesRowOf('sa', $lowest))->toBe([true, true])
            ->and(catalogAudiencesListing($p['product']))->toMatchArray(['for_individuals' => true, 'for_companies' => true]);

        // The parent switched again reaches it again.
        catalogAudiencesSwitch('sa', $middle, null, false);

        expect(catalogAudiencesRowOf('sa', $top))->toBe([false, true])
            ->and(catalogAudiencesRowOf('sa', $middle))->toBe([false, false])
            ->and(catalogAudiencesRowOf('sa', $lowest))->toBe([true, false])
            ->and(catalogAudiencesListing($p['product']))->toMatchArray(['for_individuals' => true, 'for_companies' => false]);
    });

    it('audits each category whose switches changed, in that store, from what was to what is — and writes and records nothing when nothing changes', function () {
        $top = Px::category('Kitchens');
        $below = Px::category('Drawers', $top);
        catalogAudiencesSwitch('sa', $below, false);

        catalogAudiencesSwitch('sa', $top, false, false);

        $audits = DB::table('platform.audit_entries')->where('action', 'catalog.category.audience_set')->orderBy('id')->get(['subject_id', 'store_id', 'changes']);
        $changes = static function (string $subject) use ($audits): array {
            $entries = $audits->where('subject_id', $subject)->values();
            $last = (array) json_decode((string) $entries->last()?->changes, true);
            ksort($last);

            return $last;
        };

        expect($audits->pluck('store_id')->unique()->values()->all())->toBe([Fx::storeId('sa')])
            ->and($audits->where('subject_id', $below)->count())->toBe(2)
            ->and($changes($top))->toBe(['for_companies' => [true, false], 'for_individuals' => [true, false]])
            // Its Individuals switch was already off: only Companies changed.
            ->and($changes($below))->toBe(['for_companies' => [true, false]]);

        $count = DB::table('platform.audit_entries')->count();
        $queries = Cx::recordQueries();
        catalogAudiencesSwitch('sa', $top, false);
        $writes = array_filter((array) $queries, static fn (array $query): bool => preg_match('/^\s*(insert|update|delete)\b.*\bcatalog\b/is', $query['sql']) === 1);

        expect(DB::table('platform.audit_entries')->count())->toBe($count)
            ->and(array_values($writes))->toBe([]);
    });
});

describe('a sub-category added or moved (P24)', function () {
    it('gives a sub-category added under a parent the parent\'s switches, in every store, audited there', function () {
        $parent = Px::category('Kitchens');
        catalogAudiencesSwitch('sa', $parent, false);
        catalogAudiencesSwitch('eg', $parent, null, false);

        $child = Px::category('Drawers', $parent);
        $top = Px::category('Wardrobes');

        expect(catalogAudiencesRowOf('sa', $child))->toBe([false, true])
            ->and(catalogAudiencesRowOf('eg', $child))->toBe([true, false])
            ->and(catalogAudiencesRowOf('ae', $child))->toBeNull()
            // At the top it has no parent: both see it.
            ->and(DB::table('catalog.store_category_audiences')->where('category_id', $top)->count())->toBe(0)
            ->and(DB::table('platform.audit_entries')->where('action', 'catalog.category.audience_set')->where('subject_id', $child)->orderBy('store_id')->pluck('store_id')->all())
            ->toEqualCanonicalizing([Fx::storeId('sa'), Fx::storeId('eg')]);
    });

    it('gives a branch moved under a parent the parent\'s switches in every store, its products\' rows written again; moved to the top, it keeps its own', function () {
        $closed = Px::category('Kitchens');
        $open = Px::category('Wardrobes');
        $branch = Px::category('Drawers');
        $below = Px::category('Runners', $branch);
        $p = catalogAudiencesProduct($below, stores: ['sa', 'eg']);
        catalogAudiencesSwitch('sa', $closed, false);
        // The branch's own switch in a store where the new parent has none: the parent's both win.
        catalogAudiencesSwitch('eg', $branch, null, false);

        Fx::asSystem(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($branch, $closed)));

        expect(catalogAudiencesRowOf('sa', $branch))->toBe([false, true])
            ->and(catalogAudiencesRowOf('sa', $below))->toBe([false, true])
            ->and(catalogAudiencesRowOf('eg', $branch))->toBe([true, true])
            ->and(catalogAudiencesRowOf('eg', $below))->toBe([true, true])
            ->and(catalogAudiencesListing($p['product']))->toMatchArray(['for_individuals' => false, 'for_companies' => true])
            ->and(catalogAudiencesListing($p['product'], 'eg'))->toMatchArray(['for_individuals' => true, 'for_companies' => true]);

        // To the top: no parent to take switches from — it keeps what it has.
        Fx::asSystem(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($branch, null)));

        expect(catalogAudiencesRowOf('sa', $branch))->toBe([false, true])
            ->and(catalogAudiencesRowOf('sa', $below))->toBe([false, true]);

        // Under a parent nobody switched: both, everywhere.
        Fx::asSystem(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($branch, $open)));

        expect(catalogAudiencesRowOf('sa', $below))->toBe([true, true])
            ->and(catalogAudiencesListing($p['product']))->toMatchArray(['for_individuals' => true, 'for_companies' => true]);
    });
});

describe('the listing', function () {
    it('is written by each change in its own transaction, exactly as the repair writes it', function (Closure $prepare) {
        $top = Px::category('Kitchens');
        $category = Px::category('Drawers', $top);
        $p = catalogAudiencesProduct($category, ['60 cm', '80 cm', '90 cm'], ['sa', 'eg']);
        $change = Fx::asSystem(static fn (): Closure => $prepare($p, $top, $category));
        $before = catalogAudiencesSnapshot();

        Fx::asSystem($change);
        $after = catalogAudiencesSnapshot();
        Fx::asSystem(fn () => app(RebuildListingHandler::class)->handle(new RebuildListing));

        expect($after)->not->toBe($before)
            ->and(catalogAudiencesSnapshot())->toBe($after);
    })->with([
        'a parent switched off for individuals' => [fn (array $p, string $top) => fn () => catalogAudiencesSwitch('sa', $top, false)],
        'its own category switched off for companies' => [fn (array $p, string $top, string $category) => fn () => catalogAudiencesSwitch('eg', $category, null, false)],
        'moved under a parent switched off' => [function (array $p, string $top, string $category) {
            $closed = Px::category('Lighting');
            catalogAudiencesSwitch('sa', $closed, false, false);

            return fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($category, $closed));
        }],
        'its first variant sold wholesale only' => [fn (array $p) => fn () => catalogAudiencesSell('sa', $p['product'], [$p['variants'][0] => 'W'])],
        'every variant sold wholesale only' => [fn (array $p) => fn () => catalogAudiencesSell('sa', $p['product'], array_fill_keys($p['variants'], 'W'))],
        'its variants put in another order' => [fn (array $p) => fn () => app(OrderVariantsHandler::class)->handle(new OrderVariants($p['product'], [$p['variants'][2]]))],
    ]);

    it('says individuals see a product only with its category on for them and a variant sold retail, companies by their switch alone, and each side\'s card the first variant it can buy', function () {
        $category = Px::category('Kitchens');
        ['product' => $product, 'variants' => [$sixty, $eighty, $ninety]] = catalogAudiencesProduct($category, ['60 cm', '80 cm', '90 cm']);

        // Each side's card, in the product's own order: position, then id.
        expect(catalogAudiencesListing($product))->toBe([
            'for_individuals' => true, 'for_companies' => true, 'sells_retail' => true, 'sells_wholesale' => false,
            'card_variant_id' => $sixty, 'retail_card_variant_id' => $sixty,
        ]);

        // A mixed product: individuals keep it, their card on the first size sold retail.
        catalogAudiencesSell('sa', $product, [$sixty => 'W', $eighty => 'RW']);

        expect(catalogAudiencesListing($product))->toBe([
            'for_individuals' => true, 'for_companies' => true, 'sells_retail' => true, 'sells_wholesale' => true,
            'card_variant_id' => $sixty, 'retail_card_variant_id' => $eighty,
        ]);

        Fx::asSystem(fn () => app(OrderVariantsHandler::class)->handle(new OrderVariants($product, [$ninety])));

        expect(catalogAudiencesListing($product))->toMatchArray(['card_variant_id' => $ninety, 'retail_card_variant_id' => $ninety]);

        // All wholesale only: not shown to individuals at all (14(e)), whatever their switch.
        catalogAudiencesSell('sa', $product, [$eighty => 'W', $ninety => 'W']);

        expect(catalogAudiencesListing($product))->toBe([
            'for_individuals' => false, 'for_companies' => true, 'sells_retail' => false, 'sells_wholesale' => true,
            'card_variant_id' => $ninety, 'retail_card_variant_id' => null,
        ]);

        // Companies follow their switch alone.
        catalogAudiencesSwitch('sa', $category, true, false);

        expect(catalogAudiencesListing($product))->toMatchArray(['for_individuals' => false, 'for_companies' => false]);
    });

    it('refuses in the database what the writer never writes: individuals shown a product with nothing retail, a retail card without retail', function (string $constraint, array $row) {
        $p = catalogAudiencesProduct(Px::category('Kitchens'));

        expect(fn () => DB::table('catalog.listing')->where('product_id', $p['product'])->update($row))->toThrow(QueryException::class, $constraint);
    })->with([
        'shown to individuals, nothing retail' => ['listing_individuals_retail', ['sells_retail' => false, 'retail_card_variant_id' => null]],
        'retail with no retail card' => ['listing_retail_card', ['retail_card_variant_id' => null]],
        'a retail card with nothing retail' => ['listing_retail_card', ['for_individuals' => false, 'sells_retail' => false]],
    ]);
});

describe('what each viewer sees in the shop', function () {
    it('leaves a category switched off for individuals out of their menu, pages, search and suggestions — there only; companies and guests still see it', function () {
        $top = Px::category('Kitchens');
        $category = Px::category('Drawers', $top);
        $p = catalogAudiencesProduct($category, stores: ['sa', 'eg']);
        $brand = catalogAudiencesBrandOf($p['product']);
        $seenByAll = ['menu' => [$top, $category], 'category' => [$p['product']], 'brand' => [$p['product']], 'search' => [$p['product']], 'suggest' => [$p['product']]];

        catalogAudiencesSwitch('sa', $top, false);

        expect(catalogAudiencesSeen(ShopperKind::Individual, $category, $brand))->toBe(['menu' => [], 'category' => null, 'brand' => [], 'search' => [], 'suggest' => []])
            ->and(catalogAudiencesSeen(ShopperKind::Company, $category, $brand))->toBe($seenByAll)
            ->and(catalogAudiencesSeen(ShopperKind::Guest, $category, $brand))->toBe($seenByAll)
            ->and(catalogAudiencesSeen(ShopperKind::Individual, $category, $brand, 'eg'))->toBe($seenByAll)
            // A link to it: "Not available now" for them (P18), nothing to choose.
            ->and(catalogAudiencesPage($p['product'], ShopperKind::Individual)->available)->toBeFalse()
            ->and(catalogAudiencesPage($p['product'], ShopperKind::Individual)->variants)->toBe([])
            ->and(catalogAudiencesPage($p['product'], ShopperKind::Company)->available)->toBeTrue();

        // Nor suggested beside another product they do see.
        $shown = catalogAudiencesProduct(Px::category('Wardrobes'))['product'];
        DB::table('catalog.products')->where('id', $shown)->update(['brand_id' => $brand]);
        Fx::asSystem(fn () => app(RebuildListingHandler::class)->handle(new RebuildListing));

        expect(catalogAudiencesIds(app(ShopCatalog::class)->relations(catalogAudiencesStore('sa'), 'en', ShopperKind::Individual, $shown)->mayAlsoLike))->toBe([])
            ->and(catalogAudiencesIds(app(ShopCatalog::class)->relations(catalogAudiencesStore('sa'), 'en', ShopperKind::Company, $shown)->mayAlsoLike))->toBe([$p['product']]);
    });

    it('shows a guest what either kind of account sees, and a category switched off for both to no one (P25)', function () {
        $individualsOnly = Px::category('Kitchens');
        $companiesOnly = Px::category('Wardrobes');
        $nobody = Px::category('Lighting');
        $forIndividuals = catalogAudiencesProduct($individualsOnly)['product'];
        $forCompanies = catalogAudiencesProduct($companiesOnly)['product'];
        $forNobody = catalogAudiencesProduct($nobody)['product'];
        catalogAudiencesSwitch('sa', $individualsOnly, null, false);
        catalogAudiencesSwitch('sa', $companiesOnly, false);
        catalogAudiencesSwitch('sa', $nobody, false, false);
        $search = static fn (ShopperKind $viewer): array => catalogAudiencesIds(app(ShopSearch::class)->results(catalogAudiencesStore('sa'), 'en', $viewer, 'Drawer')->cards);
        $menu = static fn (ShopperKind $viewer): array => catalogAudiencesMenuIds(app(ShopCatalog::class)->menu(catalogAudiencesStore('sa'), 'en', $viewer));
        $sorted = static function (array $ids): array {
            rsort($ids);

            return $ids;
        };

        expect($search(ShopperKind::Guest))->toBe($sorted([$forIndividuals, $forCompanies]))
            ->and($search(ShopperKind::Individual))->toBe([$forIndividuals])
            ->and($search(ShopperKind::Company))->toBe([$forCompanies])
            ->and($menu(ShopperKind::Guest))->toEqualCanonicalizing([$individualsOnly, $companiesOnly])
            ->and(catalogAudiencesPage($forNobody, ShopperKind::Guest)->available)->toBeFalse()
            ->and(catalogAudiencesPage($forIndividuals, ShopperKind::Guest)->available)->toBeTrue();
    });

    // §2.2, §4.5 and §8 #33 put a guest on the companies' side - both sides, the companies' card -
    // and P25 lets a guest see a product only individuals see: there a guest is shown its wholesale
    // sizes too. The owner, 2026-10-11: "Guest sees all individuals+companies" (catalog.md §1.13).
    it('shows a guest both sides and the companies\' card, even where only individuals see the product', function () {
        $individualsOnly = Px::category('Kitchens');
        $companiesOnly = Px::category('Wardrobes');
        ['product' => $forIndividuals, 'variants' => [$sixty, $eighty]] = catalogAudiencesProduct($individualsOnly, ['60 cm', '80 cm']);
        ['product' => $forCompanies, 'variants' => [$wholesale, $retail]] = catalogAudiencesProduct($companiesOnly, ['60 cm', '80 cm']);
        catalogAudiencesSell('sa', $forIndividuals, [$sixty => 'W']);
        catalogAudiencesSell('sa', $forCompanies, [$wholesale => 'W']);
        catalogAudiencesSwitch('sa', $individualsOnly, null, false);
        catalogAudiencesSwitch('sa', $companiesOnly, false);
        $card = static function (string $categoryId): string {
            $page = app(ShopCatalog::class)->category(catalogAudiencesStore('sa'), 'en', ShopperKind::Guest, catalogAudiencesSlug('category', $categoryId));

            return $page instanceof CategoryPage ? $page->products->cards[0]->variantId : '';
        };
        $search = array_column(array_map(
            static fn (ProductCard $found): array => [$found->productId, $found->variantId],
            app(ShopSearch::class)->results(catalogAudiencesStore('sa'), 'en', ShopperKind::Guest, 'Drawer')->cards,
        ), 1, 0);

        // Only individuals see the first, who are shown its retail size; a guest is shown both, and the
        // companies' card.
        expect(catalogAudiencesVariantsShown($forIndividuals, ShopperKind::Individual))->toBe([$eighty])
            ->and(catalogAudiencesVariantsShown($forIndividuals, ShopperKind::Guest))->toBe([$sixty, $eighty])
            ->and($card($individualsOnly))->toBe($sixty)
            ->and($search[$forIndividuals] ?? null)->toBe($sixty)
            // Companies see the second: a guest is shown its wholesale size too, and the companies' card.
            ->and(catalogAudiencesVariantsShown($forCompanies, ShopperKind::Guest))->toBe([$wholesale, $retail])
            ->and($card($companiesOnly))->toBe($wholesale)
            ->and($search[$forCompanies] ?? null)->toBe($wholesale);
    });

    it('never shows an individual a product all wholesale-only, nor the wholesale sizes of another; a company and a guest see both sides', function () {
        $category = Px::category('Kitchens');
        ['product' => $mixed, 'variants' => [$sixty, $eighty]] = catalogAudiencesProduct($category, ['60 cm', '80 cm']);
        ['product' => $wholesale, 'variants' => [$only]] = catalogAudiencesProduct($category);
        catalogAudiencesSell('sa', $mixed, [$sixty => 'W', $eighty => 'R']);
        catalogAudiencesSell('sa', $wholesale, [$only => 'W']);
        $brand = catalogAudiencesBrandOf($mixed);

        expect(catalogAudiencesSeen(ShopperKind::Individual, $category, $brand)['category'])->toBe([$mixed])
            ->and(catalogAudiencesSeen(ShopperKind::Company, $category, $brand)['category'])->toEqualCanonicalizing([$mixed, $wholesale])
            ->and(catalogAudiencesSeen(ShopperKind::Guest, $category, $brand)['category'])->toEqualCanonicalizing([$mixed, $wholesale])
            ->and(catalogAudiencesVariantsShown($mixed, ShopperKind::Individual))->toBe([$eighty])
            ->and(catalogAudiencesVariantsShown($mixed, ShopperKind::Company))->toBe([$sixty, $eighty])
            ->and(catalogAudiencesVariantsShown($mixed, ShopperKind::Guest))->toBe([$sixty, $eighty])
            ->and(catalogAudiencesPage($wholesale, ShopperKind::Individual)->available)->toBeFalse()
            ->and(catalogAudiencesPage($wholesale, ShopperKind::Individual)->noindex())->toBeTrue()
            ->and(catalogAudiencesVariantsShown($wholesale, ShopperKind::Company))->toBe([$only]);
    });

    it('shows each viewer the card of the first variant they can buy, in the product\'s order', function () {
        $category = Px::category('Kitchens');
        ['product' => $product, 'variants' => [$sixty, $eighty]] = catalogAudiencesProduct($category, ['60 cm', '80 cm']);
        catalogAudiencesSell('sa', $product, [$sixty => 'W']);
        $card = static function (ShopperKind $viewer) use ($category): string {
            $page = app(ShopCatalog::class)->category(catalogAudiencesStore('sa'), 'en', $viewer, catalogAudiencesSlug('category', $category));

            return $page instanceof CategoryPage ? $page->products->cards[0]->variantId : '';
        };

        expect($card(ShopperKind::Individual))->toBe($eighty)
            ->and($card(ShopperKind::Company))->toBe($sixty)
            ->and($card(ShopperKind::Guest))->toBe($sixty)
            ->and(app(ShopSearch::class)->suggest(catalogAudiencesStore('sa'), 'en', ShopperKind::Individual, 'Drawer')[0]->variantId)->toBe($eighty);
    });

    it('shows a company account the company side whatever its company\'s state, and an individual account theirs, as Platform\'s ShopperType answers', function () {
        $individuals = Px::category('Kitchens');
        $companies = Px::category('Wardrobes');
        $forIndividuals = catalogAudiencesProduct($individuals)['product'];
        ['product' => $forCompanies, 'variants' => [$wholesale]] = catalogAudiencesProduct($companies);
        catalogAudiencesSell('sa', $forCompanies, [$wholesale => 'W']);
        catalogAudiencesSwitch('sa', $individuals, null, false);
        catalogAudiencesSwitch('sa', $companies, false);
        // A company account that never applied for a company in this store.
        $company = Fx::customer('company@example.test', 'sa', 'company');
        $person = Fx::customer('person@example.test', 'sa', 'individual');
        $seen = static fn (): array => catalogAudiencesIds(app(ShopSearch::class)->results(catalogAudiencesStore('sa'), 'en', app(ShopperType::class)->current(), 'Drawer')->cards);

        Fx::actAsCustomer($company);
        expect(app(ShopperType::class)->current())->toBe(ShopperKind::Company)
            ->and($seen())->toBe([$forCompanies]);

        Fx::actAsCustomer($person);
        expect(app(ShopperType::class)->current())->toBe(ShopperKind::Individual)
            ->and($seen())->toBe([$forIndividuals]);
    });
});

describe('the reads\' queries', function () {
    it('reads each page in the same recorded number of queries for every viewer, however many products', function () {
        $category = Px::category('Kitchens');
        $first = catalogAudiencesProduct($category, ['60 cm', '80 cm']);
        catalogAudiencesSell('sa', $first['product'], [$first['variants'][0] => 'W']);
        $brand = catalogAudiencesBrandOf($first['product']);
        $log = Cx::recordQueries();
        $count = static function (Closure $read) use ($log): int {
            $before = count($log);
            $read();

            return count($log) - $before;
        };
        // Everything the test itself looks up is looked up first: only the reads' own queries count.
        $sa = catalogAudiencesStore('sa');
        $slugs = ['category' => catalogAudiencesSlug('category', $category), 'brand' => catalogAudiencesSlug('brand', $brand), 'product' => catalogAudiencesSlug('product', $first['product'])];
        $shop = app(ShopCatalog::class);
        $search = app(ShopSearch::class);
        $reads = static fn (ShopperKind $viewer): array => [
            'menu' => fn () => $shop->menu($sa, 'en', $viewer),
            'category' => fn () => $shop->category($sa, 'en', $viewer, $slugs['category']),
            'brand' => fn () => $shop->brand($sa, 'en', $viewer, $slugs['brand']),
            'product' => fn () => $shop->product($sa, 'en', $viewer, $slugs['product']),
            'relations' => fn () => $shop->relations($sa, 'en', $viewer, $first['product']),
            'suggest' => fn () => $search->suggest($sa, 'en', $viewer, 'Drawer'),
        ];
        $measure = static function () use ($reads, $count): array {
            $counts = [];

            foreach ([ShopperKind::Guest, ShopperKind::Individual, ShopperKind::Company] as $viewer) {
                foreach ($reads($viewer) as $name => $read) {
                    // Warm: the store and its settings already in the cache, as on a second page.
                    $read();
                    $counts[$viewer->value][$name] = $count($read);
                }
            }

            return $counts;
        };

        // Measured 2026-10-11 on this branch: who is looking costs no query of its own — it narrows the
        // same reads. The shop's pages (step 6) add the frame and keep the 8 a page (frontend.md §5).
        $recorded = ['menu' => 3, 'category' => 4, 'brand' => 3, 'product' => 8, 'relations' => 5, 'suggest' => 2];
        $few = $measure();

        foreach (range(1, 4) as $more) {
            catalogAudiencesProduct($category, ['60 cm', '80 cm']);
        }

        expect($few)->toBe(['GUEST' => $recorded, 'INDIVIDUAL' => $recorded, 'COMPANY' => $recorded])
            ->and($measure())->toBe($few);
    });
});
