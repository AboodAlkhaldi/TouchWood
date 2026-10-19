<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategory;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategoryHandler;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Catalog\Public\Events\CategoryMoved;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What Pricing and Inventory ask Catalog (catalog.md §2.1, amendment 16(e), (i)): a product's variants
| in its order, the products in a category and below it, the variants switched on in a store, many
| variants and many products in one read — a fixed number of queries however many — and
| `CategoryMoved` when a category moves.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function catalogReads(): CatalogApi
{
    return app(CatalogApi::class);
}

describe('a product\'s variants and a category\'s products', function () {
    it('answers a product\'s variants in its own order, archived ones only when asked', function () {
        $ready = Px::ready(['60 cm', '80 cm', '90 cm']);
        [$sixty, $eighty, $ninety] = $ready['variants'];
        // Added in order, 1, 2, 3 (amendment 16(d)); 60 cm moved past the others.
        DB::table('catalog.variants')->where('id', $sixty)->update(['position' => 4]);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($eighty)));

        expect(catalogReads()->variantIdsOf(strtoupper($ready['product'])))->toBe([$ninety, $sixty])
            ->and(catalogReads()->variantIdsOf($ready['product'], includeArchived: true))->toBe([$eighty, $ninety, $sixty])
            ->and(catalogReads()->variantIdsOf('01k6abcdefghjkmnpqrstvwxyz'))->toBe([])
            ->and(catalogReads()->variantIdsOf('not-an-id'))->toBe([]);
    });

    it('answers the products in a category and in every category below it, any stage', function () {
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        $runners = Px::category('Runners', $drawers);
        $ready = Px::ready(['60 cm'], $runners);
        $draft = Px::product('Hinge');
        DB::table('catalog.products')->where('id', $draft)->update(['category_id' => $drawers]);
        Px::ready(['60 cm'], Px::category('Wardrobes'));
        $expected = [$ready['product'], $draft];
        sort($expected);

        $found = catalogReads()->productIdsInCategory($kitchens);
        sort($found);

        expect($found)->toBe($expected)
            ->and(catalogReads()->productIdsInCategory($runners))->toBe([$ready['product']])
            ->and(catalogReads()->productIdsInCategory('01k6abcdefghjkmnpqrstvwxyz'))->toBe([]);
    });

    it('tells the modules above when a category moves, and not when it stays', function () {
        Event::fake([CategoryMoved::class]);
        $kitchens = Px::category('Kitchens');
        $baths = Px::category('Baths');
        $drawers = Px::category('Drawers', $kitchens);

        Fx::asSystem(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($drawers, $kitchens)));
        Event::assertNotDispatched(CategoryMoved::class);

        Fx::asSystem(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($drawers, $baths)));
        Event::assertDispatchedTimes(CategoryMoved::class, 1);
        Event::assertDispatched(CategoryMoved::class, fn (CategoryMoved $event): bool => $event->categoryId === $drawers);
    });
});

describe('a store\'s variants', function () {
    it('answers the variants switched on in a store, and only there', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true, [$sixty])));

        expect(catalogReads()->switchedOnVariantIds(StoreId::fromString(Fx::storeId('sa'))))->toBe([$sixty])
            ->and(catalogReads()->switchedOnVariantIds(StoreId::fromString(Fx::storeId('eg'))))->toBe([]);

        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], false, [$sixty])));

        expect(catalogReads()->switchedOnVariantIds(StoreId::fromString(Fx::storeId('sa'))))->toBe([])
            ->and($eighty)->toBeString();
    });
});

describe('many at once', function () {
    it('answers many variants keyed by id, as one would be answered, leaving out an unknown id', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        DB::table('catalog.variants')->where('id', $eighty)->update(['weight_grams' => 900]);

        $found = catalogReads()->variants([strtoupper($sixty), $eighty, '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id', $sixty]);

        expect(array_keys($found))->toEqualCanonicalizing([$sixty, $eighty])
            ->and($found[$eighty])->toBeInstanceOf(VariantDto::class)
            ->and($found[$eighty])->toEqual(catalogReads()->variant($eighty))
            ->and($found[$sixty])->toEqual(catalogReads()->variant($sixty))
            ->and($found[$eighty]->weightGrams)->toBe(900)
            ->and($found[$eighty]->values[0]->value->en)->toBe('80 cm')
            ->and(catalogReads()->variants([]))->toBe([]);
    });

    it('answers many products keyed by id, a draft with its Arabic name only included', function () {
        $ready = Px::ready();
        $draft = Px::product(null);

        $found = catalogReads()->products([$ready['product'], strtoupper($draft), '01k6abcdefghjkmnpqrstvwxyz']);

        expect(array_keys($found))->toEqualCanonicalizing([$ready['product'], $draft])
            ->and($found[$draft])->toBeInstanceOf(ProductDto::class)
            ->and($found[$draft])->toEqual(catalogReads()->product($draft))
            ->and($found[$draft]->stage)->toBe(ProductStage::Draft)
            ->and($found[$ready['product']])->toEqual(catalogReads()->product($ready['product']))
            ->and(catalogReads()->products(['nothing']))->toBe([]);
    });

    it('reads many variants and many products in the same number of queries however many there are', function () {
        $few = Px::ready(['60 cm']);
        $many = Px::ready(['60 cm', '70 cm', '80 cm', '90 cm', '100 cm']);
        $count = function (callable $read): int {
            $queries = Cx::recordQueries();
            $read();

            return count($queries);
        };

        expect($count(fn () => catalogReads()->variants($few['variants'])))->toBe($count(fn () => catalogReads()->variants($many['variants'])))
            ->and($count(fn () => catalogReads()->products([$few['product']])))->toBe($count(fn () => catalogReads()->products([$few['product'], $many['product']])));
    });
});

describe('what a store sells, for Feedback (amendment 24)', function () {
    it('answers in one read which products the store sells now, and leaves out every other', function () {
        $choose = static fn (string $product, string $store) => Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $product, true)));
        $sold = Px::ready(['60 cm'])['product'];
        $elsewhere = Px::ready(['60 cm'])['product'];
        $unavailable = Px::ready(['60 cm'])['product'];
        $sizeUnavailable = Px::ready(['60 cm'])['product'];
        $hidden = Px::ready(['60 cm'])['product'];
        $archivedSizes = Px::ready(['60 cm']);
        $draft = Px::product('Hinge');
        $outOfStock = Px::ready(['60 cm'])['product'];
        $hiddenWithCategory = Px::ready(['60 cm'])['product'];
        $switchedOff = Px::ready(['60 cm'])['product'];
        $oneSizeLeft = Px::ready(['60 cm', '80 cm']);
        $archived = Px::ready(['60 cm'])['product'];

        foreach ([$sold, $unavailable, $sizeUnavailable, $hidden, $archivedSizes['product'], $outOfStock, $hiddenWithCategory, $switchedOff, $oneSizeLeft['product'], $archived] as $product) {
            $choose($product, 'sa');
        }

        $choose($elsewhere, 'eg');
        $sa = Fx::storeId('sa');
        DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $unavailable)->update(['not_available_now' => true]);
        DB::table('catalog.store_variants')->where('store_id', $sa)->where('product_id', $sizeUnavailable)->update(['not_available_now' => true]);
        DB::table('catalog.products')->where('id', $hidden)->update(['hidden_by_brand' => true]);
        DB::table('catalog.variants')->whereIn('id', $archivedSizes['variants'])->update(['is_archived' => true]);
        DB::table('catalog.products')->where('id', $hiddenWithCategory)->update(['hidden_by_category' => true]);
        DB::table('catalog.store_variants')->where('store_id', $sa)->where('product_id', $switchedOff)->update(['is_active' => false]);
        DB::table('catalog.variants')->where('id', $oneSizeLeft['variants'][0])->update(['is_archived' => true]);
        DB::table('catalog.products')->where('id', $archived)->update(['stage' => 'ARCHIVED', 'archived_from' => 'READY']);

        $queries = Cx::recordQueries();
        $found = catalogReads()->productsSoldIn(StoreId::fromString($sa), [
            strtoupper($sold), $elsewhere, $unavailable, $sizeUnavailable, $hidden, $archivedSizes['product'], $draft, $outOfStock,
            $hiddenWithCategory, $switchedOff, $oneSizeLeft['product'], $archived, '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id',
        ]);

        // Stock never counts: a product out of stock can still be saved and asked about (answer 19).
        expect($found)->toEqualCanonicalizing([$sold, $outOfStock, $oneSizeLeft['product']])
            ->and(count($queries))->toBe(1)
            ->and(catalogReads()->productsSoldIn(StoreId::fromString($sa), []))->toBe([]);
    });
});

describe('product cards, for Feedback (amendment 26(a))', function () {
    it('reads many products as cards in two queries, whatever became of them', function () {
        $choose = fn (string $product, string $code) => Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($code), $product, true)));
        $sold = Px::ready(['60 cm'])['product'];
        $elsewhere = Px::ready(['60 cm'])['product'];
        $archived = Px::ready(['60 cm'])['product'];
        $draft = Px::product('A draft');
        $choose($sold, 'sa');
        $choose($archived, 'sa');
        $choose($elsewhere, 'eg');
        DB::table('catalog.products')->where('id', $archived)->update(['stage' => 'ARCHIVED', 'archived_from' => 'READY']);
        DB::table('catalog.products')->where('id', $draft)->update(['name_en' => null]);
        // A second photo placed before the one it had: the first by position is the card's.
        DB::table('catalog.product_photos')->where('product_id', $sold)->update(['position' => 5]);
        $front = Cx::media();
        DB::table('catalog.product_photos')->insert(['product_id' => $sold, 'media_id' => $front, 'position' => 1]);
        $sa = StoreId::fromString(Fx::storeId('sa'));

        $queries = Cx::recordQueries();
        $cards = catalogReads()->productCards($sa, 'en', [strtoupper($sold), $elsewhere, $archived, $draft, '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id']);
        $read = count($queries);
        $arabic = catalogReads()->productCards($sa, 'ar', [$sold]);
        $slug = static fn (string $product, string $locale): string => (string) DB::table('catalog.product_slugs')->where('product_id', $product)->where('locale', $locale)->where('is_current', true)->value('slug');

        expect($read)->toBe(2)
            ->and(array_keys($cards))->toEqualCanonicalizing([$sold, $elsewhere, $archived, $draft])
            ->and([$cards[$sold]->sold, $cards[$elsewhere]->sold, $cards[$archived]->sold, $cards[$draft]->sold])->toBe([true, false, false, false])
            ->and([$cards[$sold]->stage, $cards[$archived]->stage, $cards[$draft]->stage])->toBe([ProductStage::Ready, ProductStage::Archived, ProductStage::Draft])
            ->and($cards[$sold]->name)->toBe((string) DB::table('catalog.products')->where('id', $sold)->value('name_en'))
            ->and($cards[$sold]->slug)->toBe($slug($sold, 'en'))
            ->and($cards[$sold]->photoMediaId)->toBe($front)
            ->and($arabic[$sold]->name)->toBe((string) DB::table('catalog.products')->where('id', $sold)->value('name_ar'))
            ->and($arabic[$sold]->slug)->toBe($slug($sold, 'ar'))
            // A draft's English name may still be missing; it has no photo yet here.
            ->and($cards[$draft]->photoMediaId)->toBeNull()
            ->and($cards[$draft]->name)->toBe((string) DB::table('catalog.products')->where('id', $draft)->value('name_ar'))
            ->and(catalogReads()->productCards($sa, 'en', []))->toBe([])
            ->and(catalogReads()->productCards($sa, 'en', ['not-an-id']))->toBe([]);
    });
});

describe('a brand, for Promotions (amendment 20)', function () {
    it('answers a brand with its names and whether it is switched on; nothing for an unknown id', function () {
        $brand = Px::brand('Hafele');
        DB::table('catalog.brands')->where('id', $brand)->update(['is_active' => false]);
        $found = catalogReads()->brand(strtoupper($brand));

        expect($found?->id)->toBe($brand)
            ->and($found?->nameEn)->toStartWith('Hafele')
            ->and($found?->nameAr)->toStartWith('ماركة')
            ->and($found?->isActive)->toBeFalse()
            ->and(catalogReads()->brand('01k6abcdefghjkmnpqrstvwxyz'))->toBeNull()
            ->and(catalogReads()->brand('not-an-id'))->toBeNull();

        DB::table('catalog.brands')->where('id', $brand)->update(['is_active' => true]);
        expect(catalogReads()->brand($brand)?->isActive)->toBeTrue();
    });

    it('answers many brands in one read, keyed by id, an unknown one left out (amendment 27)', function () {
        $hafele = Px::brand('Hafele');
        $blum = Px::brand('Blum');
        DB::table('catalog.brands')->where('id', $blum)->update(['is_active' => false]);

        $queries = Cx::recordQueries();
        // Typed in capitals: read as the lower-case id it is.
        $found = catalogReads()->brands([strtoupper($hafele), ' '.$blum.' ', '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id']);

        expect(count($queries))->toBe(1)
            ->and(array_keys($found))->toEqualCanonicalizing([$hafele, $blum])
            ->and($found[$hafele]->nameEn)->toStartWith('Hafele')
            ->and([$found[$hafele]->isActive, $found[$blum]->isActive])->toBe([true, false])
            ->and(catalogReads()->brands([]))->toBe([])
            ->and(catalogReads()->brands(['not-an-id']))->toBe([]);
    });
});

describe('a cart\'s line facts, for Promotions (amendment 18)', function () {
    it('answers each variant\'s product, brand and every category above its product\'s, nearest first', function () {
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        $softClose = Px::category('Soft close', $drawers);
        $deep = Px::ready(['60 cm', '80 cm'], $softClose);
        $top = Px::ready(['90 cm'], Px::category('Lighting'));
        [$sixty, $eighty] = $deep['variants'];
        $brand = (string) DB::table('catalog.products')->where('id', $deep['product'])->value('brand_id');

        $facts = catalogReads()->lineFacts([strtoupper($sixty), $eighty, $top['variants'][0], '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id', $sixty]);

        expect(array_keys($facts))->toEqualCanonicalizing([$sixty, $eighty, $top['variants'][0]])
            ->and($facts[$sixty]->variantId)->toBe($sixty)
            ->and($facts[$sixty]->productId)->toBe($deep['product'])
            ->and($facts[$sixty]->brandId)->toBe($brand)
            ->and($facts[$sixty]->categoryIds)->toBe([$softClose, $drawers, $kitchens])
            ->and($facts[$eighty]->categoryIds)->toBe([$softClose, $drawers, $kitchens])
            ->and($facts[$top['variants'][0]]->categoryIds)->toHaveCount(1)
            ->and(catalogReads()->lineFacts([]))->toBe([]);
    });

    it('answers a draft product with no category, with no category', function () {
        $draft = Px::product('Hinge');
        $variant = Px::variant($draft, '7000001');

        expect(catalogReads()->lineFacts([$variant])[$variant]->categoryIds)->toBe([])
            ->and(catalogReads()->lineFacts([$variant])[$variant]->productId)->toBe($draft);
    });

    it('answers a whole cart in one query however many lines it has', function () {
        $few = Px::ready(['60 cm'], Px::category('Doors', Px::category('Wardrobes')));
        $many = Px::ready(['60 cm', '70 cm', '80 cm', '90 cm', '100 cm']);
        $queries = Cx::recordQueries();
        catalogReads()->lineFacts($few['variants']);
        $one = count($queries);
        $queries = Cx::recordQueries();
        catalogReads()->lineFacts([...$few['variants'], ...$many['variants']]);

        expect($one)->toBe(1)->and(count($queries))->toBe(1);
    });
});

describe('a store\'s sizes and who may buy them, for Sales (amendment 29)', function () {
    it('answers many sizes in one query, each exactly as storeVariant answers it', function () {
        $choose = fn (string $product, string $code) => Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($code), $product, true)));
        $two = Px::ready(['60 cm', '80 cm']);
        $paused = Px::ready(['60 cm']);
        $archived = Px::ready(['60 cm']);
        $unchosen = Px::ready(['60 cm']);
        $byCategory = Px::ready(['60 cm']);
        $byBrand = Px::ready(['60 cm']);
        $sizePaused = Px::ready(['60 cm']);
        $sizeOff = Px::ready(['60 cm']);
        $archivedProduct = Px::ready(['60 cm']);
        $draft = Px::product('A draft');
        $draftSize = Px::variant($draft, (string) random_int(70_000_000, 79_999_999));
        $elsewhere = Px::ready(['60 cm']);

        foreach ([$byCategory, $byBrand, $sizePaused, $sizeOff, $archivedProduct] as $p) {
            $choose($p['product'], 'sa');
        }

        $choose($elsewhere['product'], 'ae');
        $choose($two['product'], 'sa');
        $choose($paused['product'], 'sa');
        $choose($archived['product'], 'sa');
        $sa = Fx::storeId('sa');
        DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $two['variants'][1])->update(['sells_wholesale' => true]);
        DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $two['product'])->update(['retail_minimum' => 2, 'retail_maximum' => 9]);
        DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $paused['product'])->update(['not_available_now' => true]);
        DB::table('catalog.variants')->where('id', $archived['variants'][0])->update(['is_archived' => true]);
        DB::table('catalog.products')->where('id', $byCategory['product'])->update(['hidden_by_category' => true]);
        DB::table('catalog.products')->where('id', $byBrand['product'])->update(['hidden_by_brand' => true]);
        DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $sizePaused['variants'][0])->update(['not_available_now' => true]);
        DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $sizeOff['variants'][0])->update(['is_active' => false]);
        DB::table('catalog.products')->where('id', $archivedProduct['product'])->update(['stage' => 'ARCHIVED', 'archived_from' => 'READY']);
        // Chosen in the UAE only: its rows there say nothing of it here.
        DB::table('catalog.store_products')->where('store_id', Fx::storeId('ae'))->where('product_id', $elsewhere['product'])->update(['retail_minimum' => 4]);
        $ids = [...$two['variants'], $paused['variants'][0], $archived['variants'][0], $unchosen['variants'][0], $byCategory['variants'][0], $byBrand['variants'][0],
            $sizePaused['variants'][0], $sizeOff['variants'][0], $archivedProduct['variants'][0], $draftSize, $elsewhere['variants'][0]];
        $store = StoreId::fromString($sa);

        $queries = Cx::recordQueries();
        $many = catalogReads()->storeVariants($store, [...array_map(strtoupper(...), $ids), '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id']);
        $read = count($queries);

        expect($read)->toBe(1)
            ->and(array_keys($many))->toEqualCanonicalizing($ids)
            ->and(catalogReads()->storeVariants($store, []))->toBe([]);

        foreach ($ids as $id) {
            expect($many[$id])->toEqual(catalogReads()->storeVariant($store, $id));
        }

        expect([$many[$two['variants'][0]]->orderable, $many[$paused['variants'][0]]->orderable, $many[$archived['variants'][0]]->orderable, $many[$unchosen['variants'][0]]->orderable])->toBe([true, false, false, false])
            ->and([$many[$two['variants'][0]]->retailMinimum, $many[$two['variants'][0]]->retailMaximum])->toBe([2, 9])
            ->and([$many[$elsewhere['variants'][0]]->isActive, $many[$elsewhere['variants'][0]]->saleModes, $many[$elsewhere['variants'][0]]->retailMinimum])->toBe([false, [], 1]);
    });

    it('answers which products the category switches leave open to each kind of shopper', function () {
        $forBoth = Px::category('Both');
        // A sub-category switched apart from its parent: the product's own category decides.
        $openChild = Px::category('Open child', Px::category('Closed parent'));
        $individualsOnly = Px::category('Homes');
        $companiesOnly = Px::category('Trade');
        $closed = Px::category('Closed');
        $products = [
            'both' => Px::ready(['60 cm'], $forBoth)['product'],
            'individuals' => Px::ready(['60 cm'], $individualsOnly)['product'],
            'companies' => Px::ready(['60 cm'], $companiesOnly)['product'],
            'closed' => Px::ready(['60 cm'], $closed)['product'],
            'no category' => Px::product('A draft'),
            'open child' => Px::ready(['60 cm'], $openChild)['product'],
        ];
        $sa = Fx::storeId('sa');
        $now = now();

        $parent = (string) DB::table('catalog.categories')->where('id', $openChild)->value('parent_id');

        foreach ([[$individualsOnly, true, false], [$companiesOnly, false, true], [$closed, false, false], [$parent, false, false]] as [$category, $individuals, $companies]) {
            DB::table('catalog.store_category_audiences')->insert(['store_id' => $sa, 'category_id' => $category, 'for_individuals' => $individuals, 'for_companies' => $companies, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Another store's switches change nothing here.
        DB::table('catalog.store_category_audiences')->insert(['store_id' => Fx::storeId('ae'), 'category_id' => $forBoth, 'for_individuals' => false, 'for_companies' => false, 'created_at' => $now, 'updated_at' => $now]);
        $open = static function (ShopperKind $viewer) use ($products, $sa): array {
            $found = catalogReads()->productsOpenTo(StoreId::fromString($sa), $viewer, [...array_values($products), 'not-an-id']);
            sort($found);

            return $found;
        };
        $expect = static function (array $names) use ($products): array {
            $ids = array_values(array_map(static fn (string $name): string => $products[$name], $names));
            sort($ids);

            return $ids;
        };

        $queries = Cx::recordQueries();
        $individual = $open(ShopperKind::Individual);
        $read = count($queries);

        expect($read)->toBe(1)
            ->and($individual)->toBe($expect(['both', 'individuals', 'no category', 'open child']))
            ->and($open(ShopperKind::Company))->toBe($expect(['both', 'companies', 'no category', 'open child']))
            ->and($open(ShopperKind::Guest))->toBe($expect(['both', 'individuals', 'companies', 'no category', 'open child']))
            ->and(catalogReads()->productsOpenTo(StoreId::fromString($sa), ShopperKind::Guest, []))->toBe([]);
    });
});
