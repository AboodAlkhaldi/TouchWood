<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\Command\SaveTheme\SaveTheme;
use Modules\Content\Application\Command\SaveTheme\SaveThemeHandler;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleTheme;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleThemeHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| W5b in a real browser (content.md §4.4, §1.8), a Super Admin's: Schedule Theme refused over another theme of the same
| store, the clash said under the dates in the page's language, naming the other theme - in English and in Arabic - and
| Preview Theme switched on for this browser and off again; each at a phone's 375 x 812, the parts as Collapsibles and
| the comparison drawn once mounted.
|
| Deliberately no RefreshDatabase: themes clash on their dates in a store, so each run starts with none (they are the
| test database's; a campaign's link to one is let go first), and names its own by its run's number (lesson 174).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    DB::table('content.campaigns')->whereNotNull('theme_id')->update(['theme_id' => null]);
    DB::table('content.unread_translations')->where('kind', 'THEME')->delete();
    DB::table('content.themes')->delete();
});

/** A theme of the store `ae` with one part's words, written through its use cases as the system, its id. */
function contentThemesScreenTheme(string $nameAr, string $nameEn, int $fromDays, int $toDays, bool $scheduled): string
{
    $typed = static fn (int $days): string => CarbonImmutable::now()->addDays($days)->setTimezone('Asia/Dubai')->format('Y-m-d 00:00');

    return Fx::asSystem(function () use ($nameAr, $nameEn, $typed, $fromDays, $toDays, $scheduled): string {
        $id = app(SaveThemeHandler::class)->handle(new SaveTheme(null, 0, $nameAr, $nameEn, $typed($fromDays), $typed($toDays), [Fx::storeId('ae')], [['part' => 'BADGE', 'textAr' => 'عرض', 'textEn' => 'Offer']]));

        if ($scheduled) {
            app(ScheduleThemeHandler::class)->handle(new ScheduleTheme($id, 1));
        }

        return $id;
    });
}

function contentThemesScreenAdmin(string $locale): string
{
    $staff = Fx::staff(superAdmin: true);
    DB::table('access.staff_users')->where('id', $staff)->update(['locale' => $locale]);

    return $staff;
}

it('refuses Schedule Theme over another theme of the store, the clash said under the dates', function (string $locale) {
    $run = Cb::run();
    $page = Cb::signInAs(contentThemesScreenAdmin($locale));
    $page->resize(375, 812);
    contentThemesScreenTheme("رمضان {$run}", "Ramadan {$run}", 30, 60, true);
    $draft = contentThemesScreenTheme("العيد {$run}", "Eid {$run}", 50, 70, false);
    $other = $locale === 'ar' ? "رمضان {$run}" : "Ramadan {$run}";

    $page->navigate("/admin/content/themes/{$draft}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"theme-form\"]') !== null && document.querySelector('[data-test=\"theme-compare\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    // A part opens as its Collapsible, its fields and size notes inside.
    $page->click('[data-test="part-ANNOUNCEMENT-toggle"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"part-ANNOUNCEMENT\"] [data-test=\"image-part_announcement_desktop_any\"]') !== null"))->toBeTrue();

    $page->click('[data-test="schedule"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"starts\"]')?.textContent.includes('{$other}') === true"))->toBeTrue()
        ->and(DB::table('content.themes')->where('id', $draft)->value('is_scheduled'))->toBeFalse()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
})->with(['en', 'ar']);

it('takes what a refused Schedule saved as the form\'s own, so Preview Theme opens again', function () {
    $run = Cb::run();
    $page = Cb::signInAs(contentThemesScreenAdmin('en'));
    $page->resize(375, 812);
    contentThemesScreenTheme("رمضان {$run}", "Ramadan {$run}", 30, 60, true);
    $draft = contentThemesScreenTheme("العيد {$run}", "Eid {$run}", 50, 70, false);

    $page->navigate("/admin/content/themes/{$draft}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"preview-start\"]') !== null && document.querySelector('[data-test=\"preview-start\"]').getAttribute('aria-disabled') !== 'true'"))->toBeTrue();

    // A part's words changed: Preview waits for a save, as the shop draws what is saved.
    $page->click('[data-test="part-BADGE-toggle"]');
    expect(browserSoon($page, "document.querySelector('#part_badge_en') !== null"))->toBeTrue();
    $page->type('#part_badge_en', "Gift {$run}");
    expect(browserSoon($page, "document.querySelector('[data-test=\"preview-start\"]').getAttribute('aria-disabled') === 'true'"))->toBeTrue();

    // Schedule saves, then is refused over the other theme: the save stands (9.3e E19), so the form holds no change
    // now and Preview opens again (PR 8's second review).
    $page->click('[data-test="schedule"]');
    expect(browserSoon($page, "document.body.textContent.includes('Theme saved, not scheduled') && document.querySelector('[data-test=\"preview-start\"]').getAttribute('aria-disabled') !== 'true'"))->toBeTrue()
        ->and(DB::table('content.theme_parts')->where('theme_id', $draft)->where('part', 'BADGE')->value('text_en'))->toBe("Gift {$run}")
        ->and(DB::table('content.themes')->where('id', $draft)->value('is_scheduled'))->toBeFalse()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});

it('switches Preview Theme on for this browser in a staff view of the shop, and off again', function () {
    $run = Cb::run();
    $page = Cb::signInAs(contentThemesScreenAdmin('ar'));
    $page->resize(375, 812);
    $theme = contentThemesScreenTheme("الشتاء {$run}", "Winter {$run}", 10, 20, false);

    $page->navigate("/admin/content/themes/{$theme}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"preview-start\"]') !== null && document.querySelector('[data-test=\"previewing\"]') === null"))->toBeTrue();

    // The signed cookie, then the staff view of the theme's first store - the only place the shop shows a preview
    // (content.md 9.3d D5): the shop's home opens with the theme's line on it.
    $page->click('[data-test="preview-start"]');
    expect(browserSoon($page, "window.location.pathname.startsWith('/ae/') && document.querySelector('[data-test=\"preview-line\"]')?.textContent.includes('الشتاء {$run}') === true"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    // Back on its page, the panel says this browser previews it; Stop Preview there ends it.
    $page->navigate("/admin/content/themes/{$theme}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"previewing\"]')?.textContent.includes('الشتاء {$run}') === true"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->click('[data-test="preview-stop"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"previewing\"]') === null && document.querySelector('[data-test=\"preview-start\"]') !== null"))->toBeTrue();
    $page->navigate("/admin/content/themes/{$theme}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"theme-form\"]') !== null && document.querySelector('[data-test=\"previewing\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
