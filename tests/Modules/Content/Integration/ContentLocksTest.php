<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\AddHomeSection\AddHomeSection;
use Modules\Content\Application\Command\AddHomeSection\AddHomeSectionHandler;
use Modules\Content\Application\Command\DeleteBanner\DeleteBanner;
use Modules\Content\Application\Command\DeleteBanner\DeleteBannerHandler;
use Modules\Content\Application\Command\DeleteBlogCategory\DeleteBlogCategory;
use Modules\Content\Application\Command\DeleteBlogCategory\DeleteBlogCategoryHandler;
use Modules\Content\Application\Command\DeletePage\DeletePage;
use Modules\Content\Application\Command\DeletePage\DeletePageHandler;
use Modules\Content\Application\Command\OrderBanners\OrderBanners;
use Modules\Content\Application\Command\OrderBanners\OrderBannersHandler;
use Modules\Content\Application\Command\OrderBlogCategories\OrderBlogCategories;
use Modules\Content\Application\Command\OrderBlogCategories\OrderBlogCategoriesHandler;
use Modules\Content\Application\Command\OrderHomeSections\OrderHomeSections;
use Modules\Content\Application\Command\OrderHomeSections\OrderHomeSectionsHandler;
use Modules\Content\Application\Command\RemoveHomeSection\RemoveHomeSection;
use Modules\Content\Application\Command\RemoveHomeSection\RemoveHomeSectionHandler;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategory;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategoryHandler;
use Modules\Content\Application\Command\SaveFooter\SaveFooter;
use Modules\Content\Application\Command\SaveFooter\SaveFooterHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SavePopup\SavePopup;
use Modules\Content\Application\Command\SavePopup\SavePopupHandler;
use Modules\Content\Application\Command\SavePost\SavePost;
use Modules\Content\Application\Command\SavePost\SavePostHandler;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopup;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopupHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\PopupClash;
use Modules\Content\Domain\Model\Banner;
use Modules\Content\Domain\Model\BannerPlacement;
use Modules\Content\Domain\Model\SmartBar;
use Modules\Content\Domain\Model\SmartBarScope;
use Modules\Content\Domain\Repository\Banners;
use Modules\Content\Domain\Repository\SmartBars;
use Modules\Content\Domain\ValueObject\Schedule;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The checks no row can hold, each under its store's lock (content.md §1.1 rule 12; PR 3 review): an address two
| items would take, a home section added twice, a banner's or a blog category's place, the bar's and the footer's
| first save, a pop-up rescheduled over another. Each lock is proved by what it leaves behind - its key, the
| transaction it is taken in (level 2: RefreshDatabase's is 1), and that it comes before any row is locked (lesson
| 110) - since two requests at once cannot be raced in one process. And a file or a category deleted between its
| check and the row naming it is refused in the check's words, not as a server error.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * The advisory locks and row locks a piece of work takes, in order: `lock:{key}@{level}` or `row`.
 *
 * @return list<string>
 */
function locksTaken(callable $work): array
{
    /** @var ArrayObject<int, string> $taken */
    $taken = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($taken): void {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $taken[] = 'lock:'.(string) ($query->bindings[0] ?? '').'@'.DB::transactionLevel();
        } elseif (str_contains($query->sql, 'FOR UPDATE')) {
            $taken[] = 'row';
        }
    });
    $work();

    return array_values($taken->getArrayCopy());
}

function lockKey(string $topic): string
{
    return "lock:content:{$topic}:".Fx::storeId('sa').'@2';
}

/**
 * Whether the lock is taken, and before any row is locked.
 *
 * @param  list<string>  $taken
 */
function lockedFirst(array $taken, string $key): bool
{
    $at = array_search($key, $taken, true);
    $row = array_search('row', $taken, true);

    return $at !== false && ($row === false || $at < $row);
}

describe('the address lock', function () {
    it('is taken before any row when a page is written, new or changed, and when it is deleted', function () {
        Cx::editor([ContentPermissions::PAGE_MANAGE]);
        $input = static fn (array $with = []): DocumentInput => new DocumentInput(...['id' => null, 'storeId' => Fx::storeId('sa'), 'version' => 0, 'titleAr' => 'دليل', 'titleEn' => 'Guide', ...$with]);
        $page = null;

        expect(lockedFirst(locksTaken(function () use (&$page, $input): void {
            $page = app(SavePageHandler::class)->handle(new SavePage($input()));
        }), lockKey('slugs')))->toBeTrue()
            ->and(lockedFirst(locksTaken(fn () => app(SavePageHandler::class)->handle(new SavePage($input(['id' => $page, 'version' => 1])))), lockKey('slugs')))->toBeTrue()
            ->and(lockedFirst(locksTaken(fn () => app(DeletePageHandler::class)->handle(new DeletePage((string) $page))), lockKey('slugs')))->toBeTrue();
    });

    it('is taken by a blog category\'s save after the categories\' lock and before its row', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE]);
        $category = app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أدلة', 'Guides'));

        $taken = locksTaken(fn () => app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory($category, null, 'أدلة', 'How-tos')));

        expect(array_slice($taken, 0, 3))->toBe([lockKey('blog_categories'), lockKey('slugs'), 'row']);
    });

    it('is taken by a blog category\'s delete after the categories\' lock and before its row', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE]);
        $category = app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أدلة', 'Guides'));

        $taken = locksTaken(fn () => app(DeleteBlogCategoryHandler::class)->handle(new DeleteBlogCategory($category)));

        expect(array_slice($taken, 0, 3))->toBe([lockKey('blog_categories'), lockKey('slugs'), 'row']);
    });

    it('is taken by a post saved into a category before the category\'s row, as a category\'s save takes them', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE]);
        $category = app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أدلة', 'Guides'));
        $input = new DocumentInput(null, Fx::storeId('sa'), 0, 'دليل', 'Guide', categoryId: $category);

        // Reversed, a post holding the category's row would wait for the addresses a category's save holds while it
        // waits for that row: the one pairing that could deadlock.
        $taken = locksTaken(fn () => app(SavePostHandler::class)->handle(new SavePost($input)));

        expect(array_slice($taken, 0, 2))->toBe([lockKey('slugs'), 'row']);
    });
});

describe('the store\'s list locks', function () {
    it('holds the home sections\' list while one is added, removed or the list ordered', function () {
        Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
        $ids = array_values(array_map(strval(...), DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->orderBy('position')->pluck('id')->all()));

        expect(lockedFirst(locksTaken(fn () => app(RemoveHomeSectionHandler::class)->handle(new RemoveHomeSection($ids[7]))), lockKey('home')))->toBeTrue()
            ->and(lockedFirst(locksTaken(fn () => app(AddHomeSectionHandler::class)->handle(new AddHomeSection(Fx::storeId('sa'), 'CLIENT_LOGOS'))), lockKey('home')))->toBeTrue();

        $ids = array_values(array_map(strval(...), DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->orderBy('position')->pluck('id')->all()));
        expect(lockedFirst(locksTaken(fn () => app(OrderHomeSectionsHandler::class)->handle(new OrderHomeSections(Fx::storeId('sa'), array_reverse($ids)))), lockKey('home')))->toBeTrue();
    });

    it('holds a store\'s banners while one is added, deleted or they are ordered', function () {
        Cx::editor([ContentPermissions::BANNER_MANAGE]);
        $banner = null;

        expect(lockedFirst(locksTaken(function () use (&$banner): void {
            $banner = Cx::banner();
        }), lockKey('banners')))->toBeTrue()
            ->and(lockedFirst(locksTaken(fn () => app(OrderBannersHandler::class)->handle(new OrderBanners(Fx::storeId('sa'), 'HOME_PHONE', [(string) $banner]))), lockKey('banners')))->toBeTrue()
            ->and(lockedFirst(locksTaken(fn () => app(DeleteBannerHandler::class)->handle(new DeleteBanner((string) $banner))), lockKey('banners')))->toBeTrue();
    });

    it('holds a store\'s blog categories while one is added, deleted or they are ordered', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE]);
        $category = null;

        expect(lockedFirst(locksTaken(function () use (&$category): void {
            $category = app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أدلة', 'Guides'));
        }), lockKey('blog_categories')))->toBeTrue()
            ->and(lockedFirst(locksTaken(fn () => app(OrderBlogCategoriesHandler::class)->handle(new OrderBlogCategories(Fx::storeId('sa'), [(string) $category]))), lockKey('blog_categories')))->toBeTrue()
            ->and(lockedFirst(locksTaken(fn () => app(DeleteBlogCategoryHandler::class)->handle(new DeleteBlogCategory((string) $category))), lockKey('blog_categories')))->toBeTrue();
    });

    it('holds the store\'s frame while the bar or the footer is saved, a first save included', function () {
        Cx::editor([ContentPermissions::BAR_MANAGE, ContentPermissions::HOMEPAGE_MANAGE]);

        expect(lockedFirst(locksTaken(fn () => app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, 'خصم', 'Sale', null, true))), lockKey('frame')))->toBeTrue()
            ->and(lockedFirst(locksTaken(fn () => app(SaveFooterHandler::class)->handle(new SaveFooter(Fx::storeId('sa'), 0, null, null, null, null, null, null, null, null, []))), lockKey('frame')))->toBeTrue();
    });
});

describe('a scheduled pop-up changed', function () {
    it('is checked for a clash under the store\'s pop-up lock, taken before its row', function () {
        Cx::editor([ContentPermissions::POPUP_MANAGE]);
        $popup = Cx::popup(['startsAt' => Cx::typed(60)]);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1));

        $taken = locksTaken(fn () => app(SavePopupHandler::class)->handle(new SavePopup($popup, null, 2, 'Welcome', 'أهلًا', 'Welcome', 'نص', 'Text', null, null, null, null, null, Cx::typed(60), null)));

        expect(lockedFirst($taken, lockKey('popups')))->toBeTrue();
    });

    it('is refused when its new dates reach another scheduled pop-up of the store, naming it', function () {
        Cx::editor([ContentPermissions::POPUP_MANAGE]);
        $first = Cx::popup(['name' => 'Ramadan', 'endsAt' => Cx::typed(60)]);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($first, 1));
        $second = Cx::popup(['startsAt' => Cx::typed(120)]);
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($second, 1));

        try {
            app(SavePopupHandler::class)->handle(new SavePopup($second, null, 2, 'Welcome', 'أهلًا', 'Welcome', 'نص', 'Text', null, null, null, null, null, Cx::typed(30), null));
        } catch (PopupClash $clash) {
            expect($clash->popup)->toBe('Ramadan')
                ->and(DB::table('content.popups')->where('id', $second)->value('version'))->toBe(2);

            return;
        }

        throw new LogicException('Not refused.');
    });
});

describe('a file or a category deleted after its check', function () {
    it('refuses the row naming a file that is gone in the check\'s words', function () {
        $banner = new Banner(strtolower((string) Str::ulid()), Fx::storeId('sa'), BannerPlacement::HomePhone, null, 'Gone', null, false, 'وصف', null, new Schedule(false, null, null), 0, ['PHONE:any' => strtolower((string) Str::ulid())], 1, null, Cx::now());

        try {
            DB::transaction(fn () => app(Banners::class)->add($banner));
        } catch (InvalidContentInput $refused) {
            expect([$refused->attribute, $refused->rule])->toBe(['images', InvalidContentInput::IMAGE_FILE])
                ->and(DB::table('content.banners')->where('id', $banner->id)->exists())->toBeFalse();

            return;
        }

        throw new LogicException('Not refused.');
    });

    it('refuses the row naming a category that is gone in the check\'s words', function () {
        $bar = new SmartBar(strtolower((string) Str::ulid()), Fx::storeId('sa'), SmartBarScope::Category, strtolower((string) Str::ulid()), null, 'خصم', 'Sale', null, null, null);

        try {
            DB::transaction(fn () => app(SmartBars::class)->add($bar, null));
        } catch (InvalidContentInput $refused) {
            expect([$refused->attribute, $refused->rule])->toBe(['category', InvalidContentInput::CATEGORY]);

            return;
        }

        throw new LogicException('Not refused.');
    });

    it('lets any other failure through as it is', function () {
        // A store that is none: its key's failure is no refusal of the check's.
        $bar = new SmartBar(strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), SmartBarScope::Category, Px::category(), null, 'خصم', 'Sale', null, null, null);

        expect(fn () => DB::transaction(fn () => app(SmartBars::class)->add($bar, null)))->toThrow(QueryException::class);
    });
});
