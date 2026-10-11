<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPair;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPairHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\EditBrand\EditBrand;
use Modules\Catalog\Application\Command\EditBrand\EditBrandHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\PruneSearchLog\PruneSearchLog;
use Modules\Catalog\Application\Command\PruneSearchLog\PruneSearchLogHandler;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWords;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWordsHandler;
use Modules\Catalog\Application\Query\Shop\ProductCard;
use Modules\Catalog\Application\Query\Shop\ShopSearch;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Infrastructure\Queue\PruneSearchLogJob;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The shop's search (catalog.md §1.11, §8 #17, #18; amendment 5(c)–(h)): both names, the search
| words, the word pairs and the categories' names — never the brand, the code or the description —
| ranked exact, prefix, nearest, a search word or pair, a category's name, ties by sales rank; and the
| search log, which keeps no person, only what was submitted, for twelve months.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function catalogSearchStore(string $code = 'sa'): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/**
 * A ready product with these names, in this category or a new one, chosen in these stores.
 *
 * @param  list<string>  $stores
 */
function catalogSearchProduct(string $nameEn, string $nameAr, ?string $categoryId = null, array $stores = ['sa'], ?string $brandId = null): string
{
    $id = Px::ready(categoryId: $categoryId ?? Px::category('Panels'))['product'];
    $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No product.');
    $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];

    Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails(
        $id, $nameAr, $nameEn, $brandId ?? $product->brandId(),
        descriptionAr: $text, descriptionEn: $text, categoryId: $product->categoryId(),
    )));

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $id, true)));
    }

    return $id;
}

/**
 * @param  list<ProductCard>  $cards
 * @return list<string>
 */
function catalogSearchIds(array $cards): array
{
    return array_map(static fn (ProductCard $card): string => $card->productId, $cards);
}

/**
 * @return list<string>
 */
function catalogSearchFound(string $typed, string $locale = 'en', string $store = 'sa'): array
{
    return catalogSearchIds(app(ShopSearch::class)->suggest(catalogSearchStore($store), $locale, $typed, ShopSearch::SUGGESTIONS));
}

describe('the ranking', function () {
    it('ranks exact, then prefix, then nearest, then a search word, then a category\'s name — ties by sales rank', function () {
        $exact = catalogSearchProduct('Drawer', 'درج');
        $prefixHigher = catalogSearchProduct('Drawer runner', 'سكة درج');
        $prefixLower = catalogSearchProduct('Drawer slide', 'منزلق درج');
        // Not a prefix of it, but near (pg_trgm's word similarity): one letter short (0.71), and
        // one doubled (0.67) — added after, so newest-first alone would put it first.
        $nearer = catalogSearchProduct('Drawe panel', 'لوح');
        $near = catalogSearchProduct('Drawwer panel', 'لوح مزدوج');
        $word = catalogSearchProduct('Slide', 'منزلق');
        Fx::asSystem(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($word, ['drawer'])));
        $category = catalogSearchProduct('Panel', 'لوح خشب', Px::category('Drawers'));
        // Sales pushes ranks from stage 6 (§2.2): the older of the two sells better.
        DB::table('catalog.listing')->where('product_id', $prefixLower)->update(['sales_rank' => 2]);
        DB::table('catalog.listing')->where('product_id', $prefixHigher)->update(['sales_rank' => 1]);
        // Found by nothing but its description, its brand or its code, a product is not found.
        catalogSearchProduct('Lamp', 'مصباح');

        $found = app(ShopSearch::class)->results(catalogSearchStore(), 'en', 'Drawer');

        expect(catalogSearchIds($found->cards))->toBe([$exact, $prefixHigher, $prefixLower, $nearer, $near, $word, $category])
            ->and($found->total)->toBe(7);

        // The total counts every match, not only those on the page.
        $page = app(ShopSearch::class)->results(catalogSearchStore(), 'en', 'drawer.', 2);

        expect(catalogSearchIds($page->cards))->toBe([$exact, $prefixHigher])
            ->and($page->total)->toBe(7);
    });

    it('takes the other language\'s name, whole, as an exact match too', function () {
        $exact = catalogSearchProduct('Pivot', 'مفصلة');
        // Newer, and a prefix match: newest-first alone would put it first.
        $prefix = catalogSearchProduct('Door pivot', 'مفصلة باب');

        expect(catalogSearchFound('مفصلة'))->toBe([$exact, $prefix]);
    });

    it('finds an Arabic name typed with one letter wrong, by nearness', function () {
        $id = catalogSearchProduct('Soft hinge', 'مفصلة ناعمة');

        expect(catalogSearchFound('مفصلا', 'ar'))->toBe([$id]);
    });

    it('finds a product by either language\'s name on every page, shown in the page\'s language', function () {
        $id = catalogSearchProduct('Pivot', 'مفصلة');

        $cards = app(ShopSearch::class)->suggest(catalogSearchStore(), 'en', 'مفصله');

        expect(catalogSearchIds($cards))->toBe([$id])
            ->and($cards[0]->name)->toBe('Pivot')
            ->and(catalogSearchFound('pivot', 'ar'))->toBe([$id]);
    });

    it('reads Arabic as search compares it: marks, alef forms, teh marbuta and digits', function () {
        $id = catalogSearchProduct('Bracket', 'مِفْصَلة أبواب ٣٥');

        expect(catalogSearchFound('مفصله ابواب 35', 'ar'))->toBe([$id])
            ->and(catalogSearchFound('مُفصلة أبواب', 'ar'))->toBe([$id]);
    });

    it('widens a word, or a run of words, by the shared word pairs', function () {
        $hinge = catalogSearchProduct('Pivot', 'مفصلة');
        $soft = catalogSearchProduct('Damper', 'مخمد');
        Fx::asSystem(fn () => app(SetSearchWordsHandler::class)->handle(new SetSearchWords($soft, ['ناعم'])));
        // Its words apart and in another order: found by what was typed, with or without the pair.
        $apart = catalogSearchProduct('Close soft drawer', 'درج ناعم الاغلاق');

        expect(catalogSearchFound('hinge'))->toBe([])
            ->and(catalogSearchFound('soft close'))->toBe([$apart]);

        Fx::asSystem(fn () => app(AddWordPairHandler::class)->handle(new AddWordPair('مفصلة', 'hinge')));
        Fx::asSystem(fn () => app(AddWordPairHandler::class)->handle(new AddWordPair('soft close', 'ناعم')));

        // Typed words in its name before the pair's partner among its search words.
        expect(catalogSearchFound('hinge'))->toBe([$hinge])
            ->and(catalogSearchFound('soft close', 'ar'))->toBe([$apart, $soft])
            // A pair's partner is one more way in, never a way around a word typed with it.
            ->and(catalogSearchFound('hinge damper'))->toBe([]);
    });

    it('finds what a shopper can order here, a product left in an inactive category included — never a secondary brand\'s, nor anything else', function () {
        $category = Px::category('Hinges');
        $left = catalogSearchProduct('Cabinet hinge', 'مفصلة خزانة', $category);
        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'LEAVE')));
        $brand = Px::brand('Tallsen');
        $row = DB::table('catalog.brands')->where('id', $brand)->first() ?? throw new LogicException('No brand.');
        Fx::asSystem(fn () => app(EditBrandHandler::class)->handle(new EditBrand($brand, (string) $row->name_ar, (string) $row->name_en, (string) $row->agency_type, false, (int) $row->position)));
        $hidden = catalogSearchProduct('Corner hinge', 'مفصلة زاوية', brandId: $brand);
        $notHere = catalogSearchProduct('Door hinge', 'مفصلة باب', stores: ['eg']);
        $unavailable = catalogSearchProduct('Glass hinge', 'مفصلة زجاج');
        Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $unavailable)));
        // A secondary brand's product is reached through its own category only (owner, 2026-10-05).
        expect(catalogSearchFound('hinge'))->toBe([$left])
            ->and(catalogSearchFound('corner'))->toBe([])
            ->and(DB::table('catalog.listing')->where('product_id', $hidden)->exists())->toBeTrue()
            ->and(catalogSearchFound('hinge', 'en', 'eg'))->toBe([$notHere]);
    });

    it('never finds a product by its code, its brand or its description', function () {
        $brand = Px::brand('Blum');
        $id = catalogSearchProduct('Lamp', 'مصباح', brandId: $brand);
        $code = (string) DB::table('catalog.variants')->where('product_id', $id)->value('code');

        expect(catalogSearchFound('lamp'))->toBe([$id])
            ->and(catalogSearchFound($code))->toBe([])
            ->and(catalogSearchFound('blum'))->toBe([])
            ->and(catalogSearchFound('drawer'))->toBe([]);
    });
});

describe('the search log', function () {
    it('keeps a submitted search — the words as read, the store, the language, how many — and no person', function () {
        catalogSearchProduct('Drawer', 'درج');

        app(ShopSearch::class)->results(catalogSearchStore(), 'en', '  DRAWER. ');
        app(ShopSearch::class)->results(catalogSearchStore('eg'), 'ar', 'مُفصلة');

        expect(Schema::getColumnListing('catalog.search_log'))->toEqualCanonicalizing(['id', 'store_id', 'locale', 'query', 'results', 'searched_at'])
            ->and(DB::table('catalog.search_log')->orderBy('id')->get(['store_id', 'locale', 'query', 'results'])->map(static fn (stdClass $row): array => (array) $row)->all())->toBe([
                ['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'query' => 'drawer', 'results' => 1],
                ['store_id' => Fx::storeId('eg'), 'locale' => 'ar', 'query' => 'مفصله', 'results' => 0],
            ]);
    });

    it('keeps nothing of the suggestions shown while typing, nor of a search with no letter or digit', function () {
        catalogSearchProduct('Drawer', 'درج');

        app(ShopSearch::class)->suggest(catalogSearchStore(), 'en', 'dra');
        app(ShopSearch::class)->results(catalogSearchStore(), 'en', '?!');

        expect(DB::table('catalog.search_log')->count())->toBe(0);
    });

    it('loses entries older than twelve months each night, run by the system', function () {
        CarbonImmutable::setTestNow('2027-06-15 01:00:00');
        $store = Fx::storeId('sa');

        foreach (['2026-06-14 23:59:59', '2026-06-15 00:59:59', '2026-06-15 01:00:00', '2027-06-01 10:00:00'] as $at) {
            DB::table('catalog.search_log')->insert(['store_id' => $store, 'locale' => 'en', 'query' => 'drawer', 'results' => 0, 'searched_at' => $at]);
        }

        Fx::asSystem(fn () => app(PruneSearchLogJob::class)->handle(app(PruneSearchLogHandler::class)));

        expect(DB::table('catalog.search_log')->orderBy('searched_at')->pluck('searched_at')->map(static fn (mixed $at): string => CarbonImmutable::parse((string) $at)->utc()->toDateTimeString())->all())
            ->toBe(['2026-06-15 01:00:00', '2027-06-01 10:00:00']);

        CarbonImmutable::setTestNow();
    });

    it('is queued nightly, and no role may run it', function () {
        $events = array_values(array_filter(app(Schedule::class)->events(), static fn ($event): bool => $event->description === PruneSearchLogJob::class));

        expect($events)->toHaveCount(1)
            ->and($events[0]->expression)->toBe('0 1 * * *');

        Cx::actAsStaffWith([CatalogPermissions::SEARCH_WORD_MANAGE]);

        expect(fn () => app(PruneSearchLogHandler::class)->handle(new PruneSearchLog))->toThrow(Unauthorized::class);
    });
});
