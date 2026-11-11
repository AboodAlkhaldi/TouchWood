<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\Command\AddHomeSection\AddHomeSection;
use Modules\Content\Application\Command\AddHomeSection\AddHomeSectionHandler;
use Modules\Content\Application\Command\DeleteBanner\DeleteBanner;
use Modules\Content\Application\Command\DeleteBanner\DeleteBannerHandler;
use Modules\Content\Application\Command\DeletePage\DeletePage;
use Modules\Content\Application\Command\DeletePage\DeletePageHandler;
use Modules\Content\Application\Command\DeletePopup\DeletePopup;
use Modules\Content\Application\Command\DeletePopup\DeletePopupHandler;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSection;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSectionHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\Command\RemoveHomeSection\RemoveHomeSection;
use Modules\Content\Application\Command\RemoveHomeSection\RemoveHomeSectionHandler;
use Modules\Content\Application\Command\SaveBanner\SaveBanner;
use Modules\Content\Application\Command\SaveBanner\SaveBannerHandler;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SavePopup\SavePopup;
use Modules\Content\Application\Command\SavePopup\SavePopupHandler;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBanner;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBannerHandler;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopup;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopupHandler;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSection;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSectionHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Content\Application\Query\Documents\DocumentsHandler;
use Modules\Content\Application\Query\HomePage\HomePageHandler;
use Modules\Content\Domain\Exception\TranslationUnread;
use Modules\Content\Domain\Model\DocumentKind;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| content.md §1.3 and the owner's P14 (2026-11-11): Translate's mark on an item outlives the session. English Translate
| filled and nobody ticked "I read the translated English." stays marked; Publish, Schedule, Show and switching the bar
| on ask for the tick again, however much later; only the tick clears the mark. Something shoppers see now never keeps
| one: its save is refused instead.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function unreadMarks(string $kind): int
{
    return DB::table('content.unread_translations')->where('kind', $kind)->count();
}

function refusedUnread(callable $work): bool
{
    try {
        $work();
    } catch (TranslationUnread) {
        return true;
    }

    return false;
}

it('keeps a page\'s mark from a save, refuses Publish without the tick, and clears it with the tick', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = Cx::page(['translated' => true]);

    expect(unreadMarks('PAGE'))->toBe(1)
        ->and(app(DocumentsHandler::class)->document(DocumentKind::Page, $page)['translationUnread'])->toBeTrue()
        // A later save that sends no mark - another session, the boxes typed over - keeps it: only the tick clears it.
        ->and(app(SavePageHandler::class)->handle(new SavePage(new DocumentInput($page, Fx::storeId('sa'), 1, 'صفحة', 'Page', bodyAr: 'نص', bodyEn: 'Text'))))->toBe($page)
        ->and(unreadMarks('PAGE'))->toBe(1)
        ->and(refusedUnread(fn () => app(PublishPageHandler::class)->handle(new PublishPage($page, 2))))->toBeTrue()
        ->and(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('DRAFT');

    app(PublishPageHandler::class)->handle(new PublishPage($page, 2, read: true));

    expect(DB::table('content.pages')->where('id', $page)->value('status'))->toBe('PUBLISHED')
        ->and(unreadMarks('PAGE'))->toBe(0);
});

it('refuses a published page\'s save with unread translated English, and takes it with the tick', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = Cx::page();
    app(PublishPageHandler::class)->handle(new PublishPage($page, 1));
    $save = fn (bool $read) => app(SavePageHandler::class)->handle(new SavePage(new DocumentInput($page, Fx::storeId('sa'), 2, 'صفحة', 'Page again', bodyAr: 'نص', bodyEn: 'Text', translated: true, read: $read)));

    expect(refusedUnread(fn () => $save(false)))->toBeTrue()
        ->and(DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'en')->value('title'))->not->toBe('Page again')
        ->and(unreadMarks('PAGE'))->toBe(0);

    $save(true);
    expect(DB::table('content.page_translations')->where('page_id', $page)->where('locale', 'en')->value('title'))->toBe('Page again');
});

it('takes the mark away with the item deleted', function () {
    Cx::editor([ContentPermissions::PAGE_MANAGE]);
    $page = Cx::page(['translated' => true]);
    app(DeletePageHandler::class)->handle(new DeletePage($page));

    expect(unreadMarks('PAGE'))->toBe(0);
});

it('asks a banner\'s and a pop-up\'s Schedule for the tick a draft\'s save left owed', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE, ContentPermissions::POPUP_MANAGE]);
    $banner = Cx::banner(['translated' => true]);
    $popup = Cx::popup(['translated' => true]);

    expect(refusedUnread(fn () => app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1))))->toBeTrue()
        ->and(refusedUnread(fn () => app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1))))->toBeTrue()
        ->and(DB::table('content.banners')->where('id', $banner)->value('is_scheduled'))->toBeFalse();

    app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1, read: true));
    app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1, read: true));

    expect(unreadMarks('BANNER') + unreadMarks('POPUP'))->toBe(0)
        // Scheduled, a banner shows what is saved without another step: unread English is refused at the save.
        ->and(refusedUnread(fn () => app(SaveBannerHandler::class)->handle(new SaveBanner($banner, Fx::storeId('sa'), 2, 'HOME_PHONE', null, 'Summer', null, false, 'تخفيضات', 'Sale', null, null, ['PHONE:any' => (string) DB::table('content.banner_images')->where('banner_id', $banner)->value('media_id')], translated: true))))->toBeTrue();
});

it('asks a home section\'s Show for the tick, and refuses unread English on a shown section\'s save', function () {
    Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
    $section = DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', 'CATEGORIES')->value('id')
        ?? app(AddHomeSectionHandler::class)->handle(new AddHomeSection(Fx::storeId('sa'), 'CATEGORIES'));
    $version = fn (): int => (int) DB::table('content.home_sections')->where('id', $section)->value('version');
    $settings = ['heading' => ['ar' => 'التصنيفات', 'en' => 'Categories']];
    DB::table('content.home_sections')->where('id', $section)->update(['is_visible' => false]);

    app(FillHomeSectionHandler::class)->handle(new FillHomeSection((string) $section, $version(), $settings, translated: true));
    $listed = collect(app(HomePageHandler::class)->sections(Fx::storeId('sa'))['sections'])->firstWhere('id', $section);

    expect($listed['translationUnread'] ?? null)->toBeTrue()
        ->and(refusedUnread(fn () => app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection((string) $section, true))))->toBeTrue();

    app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection((string) $section, true, read: true));

    expect(DB::table('content.home_sections')->where('id', $section)->value('is_visible'))->toBeTrue()
        ->and(unreadMarks('HOME_SECTION'))->toBe(0)
        ->and(refusedUnread(fn () => app(FillHomeSectionHandler::class)->handle(new FillHomeSection((string) $section, $version(), $settings, translated: true))))->toBeTrue();
});

it('keeps the bar\'s mark while it is off, and asks for the tick to switch it on', function () {
    Cx::editor([ContentPermissions::BAR_MANAGE]);
    $version = fn (): int => (int) (DB::table('content.bars')->where('store_id', Fx::storeId('sa'))->value('version') ?? 0);
    $bar = fn (bool $on, bool $translated, bool $read) => app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), $version(), 'شحن مجاني', 'Free delivery', null, $on, translated: $translated, read: $read));

    $bar(false, true, false);
    expect(unreadMarks('BAR'))->toBe(1)
        ->and(refusedUnread(fn () => $bar(true, false, false)))->toBeTrue()
        ->and(DB::table('content.bars')->where('store_id', Fx::storeId('sa'))->value('is_on'))->toBeFalse();

    $bar(true, false, true);
    expect(DB::table('content.bars')->where('store_id', Fx::storeId('sa'))->value('is_on'))->toBeTrue()
        ->and(unreadMarks('BAR'))->toBe(0);
});

it('refuses a kind no item has', function () {
    // The footer asks for both languages at its save (§1.3 #4), so it never keeps a mark.
    expect(fn () => DB::table('content.unread_translations')->insert(['kind' => 'FOOTER', 'item_id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'marked_at' => CarbonImmutable::now()]))
        ->toThrow(QueryException::class);
});

it('takes a deleted banner\'s, pop-up\'s and removed home section\'s marks with them (the #386 review)', function () {
    Cx::editor([ContentPermissions::BANNER_MANAGE, ContentPermissions::POPUP_MANAGE, ContentPermissions::HOMEPAGE_MANAGE]);
    $banner = Cx::banner(['translated' => true]);
    $popup = Cx::popup(['translated' => true]);
    $section = DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', 'CATEGORIES')->value('id')
        ?? app(AddHomeSectionHandler::class)->handle(new AddHomeSection(Fx::storeId('sa'), 'CATEGORIES'));
    DB::table('content.home_sections')->where('id', $section)->update(['is_visible' => false]);
    app(FillHomeSectionHandler::class)->handle(new FillHomeSection((string) $section, (int) DB::table('content.home_sections')->where('id', $section)->value('version'), ['heading' => ['ar' => 'التصنيفات', 'en' => 'Categories']], translated: true));

    expect(unreadMarks('BANNER') + unreadMarks('POPUP') + unreadMarks('HOME_SECTION'))->toBe(3);

    app(DeleteBannerHandler::class)->handle(new DeleteBanner($banner));
    app(DeletePopupHandler::class)->handle(new DeletePopup($popup));
    app(RemoveHomeSectionHandler::class)->handle(new RemoveHomeSection((string) $section));

    expect(unreadMarks('BANNER'))->toBe(0)
        ->and(unreadMarks('POPUP'))->toBe(0)
        ->and(unreadMarks('HOME_SECTION'))->toBe(0);
});

it('refuses a scheduled pop-up\'s save with unread translated English, and keeps no mark (the #386 review)', function () {
    Cx::editor([ContentPermissions::POPUP_MANAGE]);
    $popup = Cx::popup();
    app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1));
    $save = fn (bool $read) => app(SavePopupHandler::class)->handle(new SavePopup($popup, Fx::storeId('sa'), 2, 'Welcome', 'أهلًا بك', 'Welcome back', 'خصم على أول طلب', 'A discount', 'تسوق', 'Shop', '/categories/kitchens', null, null, null, null, translated: true, read: $read));

    expect(refusedUnread(fn () => $save(false)))->toBeTrue()
        ->and(DB::table('content.popups')->where('id', $popup)->value('title_en'))->toBe('Welcome')
        ->and(unreadMarks('POPUP'))->toBe(0);

    $save(true);
    expect(DB::table('content.popups')->where('id', $popup)->value('title_en'))->toBe('Welcome back');
});
