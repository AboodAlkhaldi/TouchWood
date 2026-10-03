<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategory;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategoryHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategory;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategoryHandler;
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategory;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategoryHandler;
use Modules\Catalog\Application\Command\RankCategories\RankCategories;
use Modules\Catalog\Application\Command\RankCategories\RankCategoriesHandler;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryLoop;
use Modules\Catalog\Domain\Exception\CategoryNotEmpty;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Events\StoreCreated;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The category tree (catalog.md §1.5, amendment 1(d)): adding with a place chosen by the adder and
| written into every store, editing, moving, deactivating with what is below and activating exactly
| what went with it, deleting an empty one — under catalog.category.manage with All stores — and
| each store's own order, under catalog.category.rank in that store.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function catalogCategoriesAdd(string $nameEn, array $overrides = []): string
{
    return app(AddCategoryHandler::class)->handle(new AddCategory(...[
        'nameAr' => 'قسم '.$nameEn,
        'nameEn' => $nameEn,
        ...$overrides,
    ]));
}

/**
 * The form as it stands, with these fields changed.
 *
 * @param  array<string, mixed>  $changes
 */
function catalogCategoriesEdit(string $categoryId, array $changes = []): void
{
    $category = app(CategoryRepository::class)->find($categoryId) ?? throw new LogicException('No such category.');

    app(EditCategoryHandler::class)->handle(new EditCategory(...[
        'categoryId' => $categoryId,
        'nameAr' => $category->name()->ar,
        'nameEn' => $category->name()->en,
        'slugAr' => $category->slugs()->ar->value,
        'slugEn' => $category->slugs()->en->value,
        'imageMediaId' => $category->imageMediaId(),
        ...$changes,
    ]));
}

/**
 * @return array<string, int> store code → the category's place there
 */
function catalogCategoriesRanks(string $categoryId): array
{
    return DB::table('catalog.store_category_ranks as r')
        ->join('platform.stores as s', 's.id', '=', 'r.store_id')
        ->where('r.category_id', $categoryId)
        ->orderBy('s.code')
        ->pluck('r.rank', 's.code')
        ->map(fn ($rank): int => (int) $rank)
        ->all();
}

/**
 * @return array{bool, bool} is_active, deactivated_with_parent
 */
function catalogCategoriesState(string $categoryId): array
{
    $row = DB::table('catalog.categories')->where('id', $categoryId)->sole();

    return [(bool) $row->is_active, (bool) $row->deactivated_with_parent];
}

describe('who may change the tree, and who a store\'s order', function () {
    it('takes catalog.category.manage with All stores, and refuses it held in one store only', function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE], ['sa']);

        expect(fn () => catalogCategoriesAdd('Kitchens'))->toThrow(Unauthorized::class)
            ->and(DB::table('catalog.categories')->count())->toBe(0);

        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);

        expect(catalogCategoriesAdd('Kitchens'))->toBeString();
    });

    it('refuses every change to the tree to someone holding only the order', function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);
        $id = catalogCategoriesAdd('Kitchens');
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_RANK]);

        expect(fn () => catalogCategoriesAdd('Doors'))->toThrow(Unauthorized::class)
            ->and(fn () => catalogCategoriesEdit($id, ['nameEn' => 'Kitchen']))->toThrow(Unauthorized::class)
            ->and(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($id, null)))->toThrow(Unauthorized::class)
            ->and(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($id)))->toThrow(Unauthorized::class)
            ->and(fn () => app(ActivateCategoryHandler::class)->handle(new ActivateCategory($id)))->toThrow(Unauthorized::class)
            ->and(fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory($id)))->toThrow(Unauthorized::class);
    });

    it('takes a store\'s order from someone holding catalog.category.rank in that store only', function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);
        $id = catalogCategoriesAdd('Kitchens');
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_RANK], ['eg']);

        app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('eg'), [$id => 5]));

        expect(catalogCategoriesRanks($id))->toBe(['ae' => 0, 'eg' => 5, 'sa' => 0])
            ->and(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('sa'), [$id => 5])))->toThrow(Unauthorized::class)
            ->and(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories('not-a-store', [$id => 5])))->toThrow(Unauthorized::class);
    });
});

describe('adding, editing and moving', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);
    });

    it('adds a category at the place its adder chose, the same in every store, audited under the categories\' lock', function () {
        $locks = Cx::recordLocks();
        $id = catalogCategoriesAdd('Kitchen Cabinets', ['nameAr' => 'خزائن المطبخ', 'rank' => 3]);
        $category = app(CategoryRepository::class)->find($id);

        expect($category?->slugs()->en->value)->toBe('kitchen-cabinets')
            ->and($category?->slugs()->ar->value)->toBe('خزائن-المطبخ')
            ->and($category?->parentId())->toBeNull()
            ->and(catalogCategoriesRanks($id))->toBe(['ae' => 3, 'eg' => 3, 'sa' => 3])
            ->and(Fx::audits('catalog.category.added', $id))->toBe(1)
            ->and(array_values(array_filter((array) $locks, static fn (array $lock): bool => $lock['key'] === 'catalog:categories')))->toBe([['key' => 'catalog:categories', 'level' => 2]]);

        $changes = json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.category.added')->value('changes'), true);

        expect($changes)->toMatchArray(['name_en' => [null, 'Kitchen Cabinets'], 'slug_en' => [null, 'kitchen-cabinets'], 'rank' => [null, 3]]);
    });

    it('places a category in a store that is off too, so it opens with the order', function () {
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));

        expect(catalogCategoriesRanks(catalogCategoriesAdd('Doors', ['rank' => 2])))->toBe(['ae' => 2, 'eg' => 2, 'sa' => 2]);
    });

    it('refuses a place outside 0 to 10000, before anything is written', function () {
        expect(fn () => catalogCategoriesAdd('Doors', ['rank' => -1]))->toThrow(InvalidCatalogAttribute::class, 'rank')
            ->and(fn () => catalogCategoriesAdd('Doors', ['rank' => 10001]))->toThrow(InvalidCatalogAttribute::class, 'rank')
            ->and(DB::table('catalog.categories')->count())->toBe(0);
    });

    it('adds under an active parent, and refuses one that is deactivated or unknown', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        $cabinets = catalogCategoriesAdd('Cabinets', ['parentId' => strtoupper($kitchens)]);

        expect(app(CategoryRepository::class)->find($cabinets)?->parentId())->toBe($kitchens);

        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($kitchens));

        expect(fn () => catalogCategoriesAdd('Handles', ['parentId' => $kitchens]))->toThrow(CategoryInactive::class)
            ->and(fn () => catalogCategoriesAdd('Handles', ['parentId' => '01j8z3k4m5n6p7q8r9s0t1v2w3']))->toThrow(CategoryNotFound::class)
            ->and(fn () => catalogCategoriesAdd('Handles', ['parentId' => 'not-an-id']))->toThrow(CategoryNotFound::class);
    });

    it('refuses a slug another category holds, or ever held, and keeps the old one held', function () {
        $doors = catalogCategoriesAdd('Doors');
        $kitchens = catalogCategoriesAdd('Kitchens');

        expect(fn () => catalogCategoriesAdd('Doors', ['nameAr' => 'أخرى']))->toThrow(SlugTaken::class);

        catalogCategoriesEdit($kitchens, ['slugEn' => 'kitchen-units']);

        expect(fn () => catalogCategoriesEdit($doors, ['slugEn' => 'kitchens']))->toThrow(SlugTaken::class)
            ->and(DB::table('catalog.category_slugs')->where('category_id', $kitchens)->where('locale', 'en')->pluck('is_current', 'slug')->map(fn ($v) => (bool) $v)->all())
            ->toEqualCanonicalizing(['kitchens' => false, 'kitchen-units' => true]);
    });

    it('records only what changed, and nothing for an edit that changes nothing', function () {
        $id = catalogCategoriesAdd('Doors');
        catalogCategoriesEdit($id);

        expect(Fx::audits('catalog.category.edited', $id))->toBe(0);

        catalogCategoriesEdit($id, ['nameEn' => 'Interior Doors', 'imageMediaId' => $image = Cx::media()]);
        $changes = (array) json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.category.edited')->value('changes'), true);

        // jsonb keeps keys shortest first; sorted here to read as written.
        ksort($changes);

        expect($changes)->toBe(['image_media_id' => [null, $image], 'name_en' => ['Doors', 'Interior Doors']]);
    });

    it('takes a public image as its photo, and refuses a private file', function () {
        expect(fn () => catalogCategoriesAdd('Doors', ['imageMediaId' => Cx::media('PRIVATE')]))->toThrow(InvalidCatalogAttribute::class, 'image_media_id');
    });

    it('moves a category under another, at the place chosen in every store, its slugs unchanged', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        $doors = catalogCategoriesAdd('Doors', ['rank' => 1]);

        app(MoveCategoryHandler::class)->handle(new MoveCategory($doors, $kitchens, 4));

        expect(app(CategoryRepository::class)->find($doors)?->parentId())->toBe($kitchens)
            ->and(app(CategoryRepository::class)->find($doors)?->slugs()->en->value)->toBe('doors')
            ->and(catalogCategoriesRanks($doors))->toBe(['ae' => 4, 'eg' => 4, 'sa' => 4]);

        $changes = (array) json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.category.moved')->value('changes'), true);

        ksort($changes);

        expect($changes)->toBe(['parent_id' => [null, $kitchens], 'rank' => [null, 4]]);
    });

    it('leaves each store\'s order alone when the parent does not change', function () {
        $doors = catalogCategoriesAdd('Doors', ['rank' => 1]);
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE, CatalogPermissions::CATEGORY_RANK]);
        app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('eg'), [$doors => 7]));

        app(MoveCategoryHandler::class)->handle(new MoveCategory($doors, null, 9));

        expect(catalogCategoriesRanks($doors))->toBe(['ae' => 1, 'eg' => 7, 'sa' => 1])
            ->and(Fx::audits('catalog.category.moved', $doors))->toBe(0);
    });

    it('never moves a category under itself or anything below it', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        $cabinets = catalogCategoriesAdd('Cabinets', ['parentId' => $kitchens]);
        $handles = catalogCategoriesAdd('Handles', ['parentId' => $cabinets]);

        expect(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($kitchens, $kitchens)))->toThrow(CategoryLoop::class)
            ->and(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($kitchens, $handles)))->toThrow(CategoryLoop::class)
            ->and(app(CategoryRepository::class)->find($kitchens)?->parentId())->toBeNull();
    });

    it('moves to the top, and refuses a deactivated new parent', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        $cabinets = catalogCategoriesAdd('Cabinets', ['parentId' => $kitchens]);
        $doors = catalogCategoriesAdd('Doors');

        app(MoveCategoryHandler::class)->handle(new MoveCategory($cabinets, null));
        expect(app(CategoryRepository::class)->find($cabinets)?->parentId())->toBeNull();

        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($kitchens));

        expect(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($doors, $kitchens)))->toThrow(CategoryInactive::class)
            ->and(app(CategoryRepository::class)->find($doors)?->parentId())->toBeNull();
    });

    it('answers a category that does not exist as not found', function () {
        expect(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory('01j8z3k4m5n6p7q8r9s0t1v2w3', null)))->toThrow(CategoryNotFound::class)
            ->and(fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory('not-an-id')))->toThrow(CategoryNotFound::class);
    });
});

describe('deactivating and activating', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);
    });

    it('takes everything below with it, and brings back only what went with it', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        $cabinets = catalogCategoriesAdd('Cabinets', ['parentId' => $kitchens]);
        $handles = catalogCategoriesAdd('Handles', ['parentId' => $cabinets]);
        $sinks = catalogCategoriesAdd('Sinks', ['parentId' => $kitchens]);
        $taps = catalogCategoriesAdd('Taps', ['parentId' => $sinks]);

        // Sinks deactivated on its own first: it, and Taps with it, stay off when Kitchens returns.
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($sinks));
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($kitchens));

        expect(catalogCategoriesState($kitchens))->toBe([false, false])
            ->and(catalogCategoriesState($cabinets))->toBe([false, true])
            ->and(catalogCategoriesState($handles))->toBe([false, true])
            ->and(catalogCategoriesState($sinks))->toBe([false, false])
            ->and(catalogCategoriesState($taps))->toBe([false, true])
            ->and(Fx::audits('catalog.category.deactivated'))->toBe(5);

        app(ActivateCategoryHandler::class)->handle(new ActivateCategory($kitchens));

        expect(catalogCategoriesState($kitchens))->toBe([true, false])
            ->and(catalogCategoriesState($cabinets))->toBe([true, false])
            ->and(catalogCategoriesState($handles))->toBe([true, false])
            ->and(catalogCategoriesState($sinks))->toBe([false, false])
            ->and(catalogCategoriesState($taps))->toBe([false, true])
            ->and(Fx::audits('catalog.category.activated'))->toBe(3);
    });

    it('refuses activating a category whose parent is deactivated', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        $cabinets = catalogCategoriesAdd('Cabinets', ['parentId' => $kitchens]);
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($kitchens));

        expect(fn () => app(ActivateCategoryHandler::class)->handle(new ActivateCategory($cabinets)))->toThrow(CategoryInactive::class)
            ->and(catalogCategoriesState($cabinets))->toBe([false, true]);
    });

    it('changes nothing, and records nothing, for a category already as asked', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        app(ActivateCategoryHandler::class)->handle(new ActivateCategory($kitchens));
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($kitchens));
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($kitchens));

        expect(Fx::audits('catalog.category.activated'))->toBe(0)
            ->and(Fx::audits('catalog.category.deactivated'))->toBe(1);
    });
});

describe('deleting', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);
    });

    it('refuses a category with a sub-category, and deletes an empty one with its slugs and places', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        $cabinets = catalogCategoriesAdd('Cabinets', ['parentId' => $kitchens]);

        expect(fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory($kitchens)))->toThrow(CategoryNotEmpty::class);

        app(DeleteCategoryHandler::class)->handle(new DeleteCategory($cabinets));

        expect(DB::table('catalog.categories')->where('id', $cabinets)->exists())->toBeFalse()
            ->and(DB::table('catalog.category_slugs')->where('category_id', $cabinets)->count())->toBe(0)
            ->and(catalogCategoriesRanks($cabinets))->toBe([])
            ->and(Fx::audits('catalog.category.deleted', $cabinets))->toBe(1);

        app(DeleteCategoryHandler::class)->handle(new DeleteCategory($kitchens));

        expect(DB::table('catalog.categories')->count())->toBe(0);
    });
});

describe('a store\'s order', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE, CatalogPermissions::CATEGORY_RANK]);
    });

    it('changes only the categories sent, in that store, each audited there', function () {
        $kitchens = catalogCategoriesAdd('Kitchens', ['rank' => 1]);
        $doors = catalogCategoriesAdd('Doors', ['rank' => 2]);
        $eg = Fx::storeId('eg');
        $locks = Cx::recordLocks();

        app(RankCategoriesHandler::class)->handle(new RankCategories($eg, [$kitchens => 2, strtoupper($doors) => 2]));

        expect(array_values(array_filter((array) $locks, static fn (array $lock): bool => $lock['key'] === 'catalog:categories')))->toBe([['key' => 'catalog:categories', 'level' => 2]])
            ->and(catalogCategoriesRanks($kitchens))->toBe(['ae' => 1, 'eg' => 2, 'sa' => 1])
            ->and(catalogCategoriesRanks($doors))->toBe(['ae' => 2, 'eg' => 2, 'sa' => 2])
            ->and(DB::table('platform.audit_entries')->where('action', 'catalog.category.ranked')->get(['subject_id', 'store_id'])->map(fn ($row) => [$row->subject_id, $row->store_id])->all())
            ->toBe([[$kitchens, $eg]]);
    });

    it('refuses an unknown category, an unknown store, and a place out of range — writing nothing', function () {
        $kitchens = catalogCategoriesAdd('Kitchens', ['rank' => 1]);
        $sa = Fx::storeId('sa');

        expect(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories($sa, [$kitchens => 3, '01j8z3k4m5n6p7q8r9s0t1v2w3' => 1])))->toThrow(CategoryNotFound::class)
            ->and(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories('01j8z3k4m5n6p7q8r9s0t1v2w3', [$kitchens => 3])))->toThrow(InvalidCatalogAttribute::class, 'store')
            ->and(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories($sa, [$kitchens => 10001])))->toThrow(InvalidCatalogAttribute::class, 'rank')
            ->and(catalogCategoriesRanks($kitchens))->toBe(['ae' => 1, 'eg' => 1, 'sa' => 1]);
    });

    it('gives a store opened later the base store\'s order, and leaves a place it has', function () {
        $kitchens = catalogCategoriesAdd('Kitchens', ['rank' => 1]);
        $doors = catalogCategoriesAdd('Doors', ['rank' => 2]);
        $sa = Fx::storeId('sa');
        $eg = Fx::storeId('eg');
        app(RankCategoriesHandler::class)->handle(new RankCategories($sa, [$kitchens => 6]));

        // Egypt as if opened now: it has a place for Doors only.
        DB::table('catalog.store_category_ranks')->where('store_id', $eg)->where('category_id', $kitchens)->delete();
        DB::table('catalog.store_category_ranks')->where('store_id', $eg)->where('category_id', $doors)->update(['rank' => 9]);

        event(new StoreCreated('e1', $eg, CarbonImmutable::now()));

        expect(catalogCategoriesRanks($kitchens))->toBe(['ae' => 1, 'eg' => 6, 'sa' => 6])
            ->and(catalogCategoriesRanks($doors))->toBe(['ae' => 2, 'eg' => 9, 'sa' => 2]);
    });
});

describe('what the database refuses behind the code', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);
    });

    it('refuses a category as its own parent, an unknown parent, and gone-with-parent while active', function (Closure $values, string $constraint) {
        $id = catalogCategoriesAdd('Kitchens');

        expect(fn () => DB::transaction(fn () => DB::table('catalog.categories')->where('id', $id)->update($values($id))))
            ->toThrow(QueryException::class, $constraint);
    })->with([
        'its own parent' => [fn (string $id): array => ['parent_id' => $id], 'categories_not_own_parent'],
        'an unknown parent' => [fn (string $id): array => ['parent_id' => '01j8z3k4m5n6p7q8r9s0t1v2w3'], 'categories_parent'],
        'gone with its parent while active' => [fn (string $id): array => ['deactivated_with_parent' => true], 'categories_with_parent_inactive'],
    ]);

    it('refuses deleting a parent behind the code', function () {
        $kitchens = catalogCategoriesAdd('Kitchens');
        catalogCategoriesAdd('Cabinets', ['parentId' => $kitchens]);

        expect(fn () => DB::transaction(fn () => DB::table('catalog.categories')->where('id', $kitchens)->delete()))
            ->toThrow(QueryException::class, 'categories_parent');
    });
});
