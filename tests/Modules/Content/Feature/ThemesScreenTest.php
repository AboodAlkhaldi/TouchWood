<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentScreen as Screen;

use function Pest\Laravel\seed;

/*
| W5 · Themes and W5b over HTTP (content.md §4.4, §1.8), a Super Admin's: the list with each store's state and the parts
| filled, New Theme saved with its parts and their images, Schedule Theme - a clash refused under the dates in §1.8's
| words - Translate's mark (P14) kept on a draft and asked for at Schedule and on a scheduled theme's save, End Now,
| Back to Draft, Delete, a part's image uploaded over HTTP (lesson 117), the page saying this browser previews it, who is
| refused, the queries. The rules are ThemesTest's.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour()->startOfSecond());
    Carbon::setTestNow(CarbonImmutable::now());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

const THEMES_SCREEN = '/admin/content/themes';

/** A moment as typed, so many days from now on the Saudi clock. */
function themesScreenTyped(int $days): string
{
    return CarbonImmutable::now()->addDays($days)->setTimezone('Asia/Riyadh')->format('Y-m-d 00:00');
}

/**
 * @param  list<string>  $stores  codes
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function themesScreenFields(array $stores = ['sa'], array $with = []): array
{
    return [
        'version' => 0, 'name_ar' => 'رمضان', 'name_en' => 'Ramadan', 'stores' => array_map(Fx::storeId(...), $stores),
        'starts_at' => themesScreenTyped(2), 'ends_at' => themesScreenTyped(20), 'intent' => 'draft',
        'parts' => [
            ['part' => 'ANNOUNCEMENT', 'textAr' => 'رمضان كريم', 'textEn' => 'Ramadan Kareem', 'link' => '/categories/kitchens', 'extras' => [], 'images' => ['DESKTOP:any' => Cx::image()]],
            ['part' => 'BADGE', 'textAr' => 'عرض', 'textEn' => 'Offer', 'link' => '', 'extras' => [], 'images' => []],
        ],
        ...$with,
    ];
}

/**
 * A new theme saved over HTTP, its id.
 *
 * @param  list<string>  $stores
 * @param  array<string, mixed>  $with
 */
function themesScreenCreate(AdminBrowser $browser, array $stores = ['sa'], array $with = []): string
{
    $browser->post(THEMES_SCREEN, themesScreenFields($stores, $with), Screen::fromPage(THEMES_SCREEN.'/new'))->assertRedirect();

    return (string) DB::table('content.themes')->orderByDesc('created_at')->orderByDesc('id')->value('id');
}

function themesScreenVersion(string $theme): int
{
    return (int) DB::table('content.themes')->where('id', $theme)->value('version');
}

it('lists every theme with its stores\' states and parts filled, and gives the form its words', function () {
    $browser = Screen::as(Fx::staff(superAdmin: true));
    $theme = themesScreenCreate($browser, ['sa', 'ae']);

    $browser->get(THEMES_SCREEN)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Themes/Index')
        ->has('themes', 1)
        ->where('themes.0.id', $theme)
        ->where('themes.0.state', 'DRAFT')
        ->where('themes.0.parts', 2)
        ->has('themes.0.stores', 2)
        ->where('themes.0.zone', fn (?string $zone): bool => $zone !== null)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_themes.title') && $words->has('content::admin_shared.state.MIXED')));

    expect(array_keys($browser->get(THEMES_SCREEN, Screen::JSON)->assertOk()->json()))->toBe(['themes', 'next']);

    $browser->get(THEMES_SCREEN.'/new')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Themes/Form')
        ->where('themeItem', null)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_themes.parts.name.BACKDROP')));

    $browser->get(THEMES_SCREEN."/{$theme}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('themeItem.id', $theme)
        ->where('themeItem.state', 'DRAFT')
        ->has('themeItem.storeIds', 2)
        ->has('parts', 2)
        ->where('parts.0.part', 'ANNOUNCEMENT')
        ->where('parts.0.textEn', 'Ramadan Kareem')
        ->where('parts.0.images.0.key', 'DESKTOP:any')
        ->has('images', 1)
        ->where('previewing', false));
});

it('schedules a theme, and refuses another overlapping it in a store under the dates, naming the clash', function () {
    $browser = Screen::as(Fx::staff(superAdmin: true));
    $first = themesScreenCreate($browser);
    $scheduled = $browser->post(THEMES_SCREEN."/{$first}", themesScreenFields(['sa'], ['version' => themesScreenVersion($first), 'intent' => 'schedule']), Screen::fromPage(THEMES_SCREEN."/{$first}"))->assertRedirect(THEMES_SCREEN."/{$first}");

    expect(AdminBrowser::flashed($scheduled, 'status'))->toBe(__('content::admin_themes.scheduled'))
        ->and((bool) DB::table('content.themes')->where('id', $first)->value('is_scheduled'))->toBeTrue();

    $browser->post('/admin/preferences', ['preference' => 'locale', 'value' => 'en']);
    $clash = $browser->post(THEMES_SCREEN, themesScreenFields(['sa'], ['name_ar' => 'العيد', 'name_en' => 'Eid', 'intent' => 'schedule', 'starts_at' => themesScreenTyped(10)]), Screen::fromPage(THEMES_SCREEN.'/new'));
    $other = (string) DB::table('content.themes')->where('name_en', 'Eid')->value('id');

    $clash->assertRedirect(THEMES_SCREEN."/{$other}");
    expect(Screen::errors($clash))->toHaveKey('starts_at')
        ->and(Screen::errors($clash)['starts_at'])->toContain('Ramadan', 'Saudi Arabia')
        ->and((bool) DB::table('content.themes')->where('id', $other)->value('is_scheduled'))->toBeFalse();
});

it('keeps Translate\'s mark on a draft theme, asks for the tick at Schedule and on a scheduled theme\'s save (P14)', function () {
    $browser = Screen::as(Fx::staff(superAdmin: true));
    $theme = themesScreenCreate($browser, ['sa'], ['translated' => true]);
    $marked = fn (): bool => DB::table('content.unread_translations')->where('kind', 'THEME')->where('item_id', $theme)->exists();

    expect($marked())->toBeTrue();
    $browser->get(THEMES_SCREEN."/{$theme}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('themeItem.translationUnread', true));

    $refused = $browser->post(THEMES_SCREEN."/{$theme}", themesScreenFields(['sa'], ['version' => themesScreenVersion($theme), 'intent' => 'schedule']), Screen::fromPage(THEMES_SCREEN."/{$theme}"));
    expect(Screen::errors($refused))->toHaveKey('form')
        ->and((bool) DB::table('content.themes')->where('id', $theme)->value('is_scheduled'))->toBeFalse();

    $browser->post(THEMES_SCREEN."/{$theme}", themesScreenFields(['sa'], ['version' => themesScreenVersion($theme), 'intent' => 'schedule', 'read' => true]), Screen::fromPage(THEMES_SCREEN."/{$theme}"))->assertRedirect(THEMES_SCREEN."/{$theme}");
    expect((bool) DB::table('content.themes')->where('id', $theme)->value('is_scheduled'))->toBeTrue()
        ->and($marked())->toBeFalse();

    // Scheduled: a save with unread English is refused as Schedule would be, and keeps no mark.
    $again = $browser->post(THEMES_SCREEN."/{$theme}", themesScreenFields(['sa'], ['version' => themesScreenVersion($theme), 'translated' => true]), Screen::fromPage(THEMES_SCREEN."/{$theme}"));
    expect(Screen::errors($again))->toHaveKey('form')
        ->and($marked())->toBeFalse();
});

it('takes a scheduled theme back to draft, deletes a draft with its mark, and ends a live one', function () {
    $browser = Screen::as(Fx::staff(superAdmin: true));
    $theme = themesScreenCreate($browser);
    $browser->post(THEMES_SCREEN."/{$theme}", themesScreenFields(['sa'], ['version' => themesScreenVersion($theme), 'intent' => 'schedule']), Screen::fromPage(THEMES_SCREEN."/{$theme}"))->assertRedirect();

    $drafted = $browser->post(THEMES_SCREEN."/{$theme}/draft", ['version' => themesScreenVersion($theme)], Screen::fromPage(THEMES_SCREEN."/{$theme}"))->assertRedirect();
    expect(AdminBrowser::flashed($drafted, 'status'))->toBe(__('content::admin_themes.drafted'))
        ->and((bool) DB::table('content.themes')->where('id', $theme)->value('is_scheduled'))->toBeFalse();

    $browser->post(THEMES_SCREEN."/{$theme}", themesScreenFields(['sa'], ['version' => themesScreenVersion($theme), 'translated' => true]), Screen::fromPage(THEMES_SCREEN."/{$theme}"))->assertRedirect();
    $deleted = $browser->post(THEMES_SCREEN."/{$theme}/delete", ['version' => themesScreenVersion($theme)], Screen::fromPage(THEMES_SCREEN."/{$theme}"))->assertRedirect(THEMES_SCREEN);
    expect(AdminBrowser::flashed($deleted, 'status'))->toBe(__('content::admin_themes.deleted'))
        ->and(DB::table('content.themes')->where('id', $theme)->exists())->toBeFalse()
        ->and(DB::table('content.unread_translations')->where('item_id', $theme)->exists())->toBeFalse();

    // A live one: scheduled from an hour ago.
    $live = themesScreenCreate($browser, ['sa'], ['name_ar' => 'الشتاء', 'name_en' => 'Winter', 'starts_at' => CarbonImmutable::now()->addMinutes(2)->setTimezone('Asia/Riyadh')->format('Y-m-d H:i')]);
    $browser->post(THEMES_SCREEN."/{$live}", themesScreenFields(['sa'], ['name_ar' => 'الشتاء', 'name_en' => 'Winter', 'version' => themesScreenVersion($live), 'intent' => 'schedule', 'starts_at' => CarbonImmutable::now()->addMinutes(2)->setTimezone('Asia/Riyadh')->format('Y-m-d H:i')]), Screen::fromPage(THEMES_SCREEN."/{$live}"))->assertRedirect();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    Carbon::setTestNow(CarbonImmutable::now());

    $browser->get(THEMES_SCREEN."/{$live}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('themeItem.state', 'LIVE'));
    $ended = $browser->post(THEMES_SCREEN."/{$live}/end", ['version' => themesScreenVersion($live)], Screen::fromPage(THEMES_SCREEN."/{$live}"))->assertRedirect();
    expect(AdminBrowser::flashed($ended, 'status'))->toBe(__('content::admin_themes.ended'));
    $browser->get(THEMES_SCREEN."/{$live}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('themeItem.state', 'ENDED'));
});

it('uploads a part\'s artwork under the themes\' permission', function () {
    Storage::fake('local');
    Storage::fake('public');

    $answer = Screen::as(Fx::staff(superAdmin: true))->post('/admin/content/uploads/theme', ['store' => Fx::storeId('sa'), 'file' => UploadedFile::fake()->image('strip.png', 2400, 80)], Screen::JSON)->assertCreated();

    expect($answer->json('name'))->toBe('strip.png')
        ->and($answer->json('width'))->toBe(2400);

    Screen::as(Fx::staffWith([ContentPermissions::CAMPAIGN_MANAGE], ['sa']))->post('/admin/content/uploads/theme', ['store' => Fx::storeId('sa'), 'file' => UploadedFile::fake()->image('x.png')], Screen::JSON)->assertForbidden();
});

it('says on the page that this browser previews the theme, after Preview Theme', function () {
    $browser = Screen::as(Fx::staff(superAdmin: true));
    $theme = themesScreenCreate($browser);

    $browser->post(THEMES_SCREEN."/{$theme}/preview")->assertRedirect();
    $browser->get(THEMES_SCREEN."/{$theme}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('previewing', true));

    // Stop Preview is a DELETE, sent as the panel's forms send one.
    $browser->post(THEMES_SCREEN.'/preview', ['_method' => 'DELETE'], Screen::fromPage(THEMES_SCREEN."/{$theme}"))->assertRedirect();
    $browser->get(THEMES_SCREEN."/{$theme}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('previewing', false));
});

it('refuses the list and a theme to anyone but a Super Admin', function () {
    $theme = themesScreenCreate(Screen::as(Fx::staff(superAdmin: true)));
    $admin = Screen::as(Fx::staffWith([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::BANNER_MANAGE], ['sa', 'ae']));

    $admin->get(THEMES_SCREEN)->assertForbidden();
    $admin->get(THEMES_SCREEN."/{$theme}")->assertForbidden();
    $admin->get(THEMES_SCREEN.'/new')->assertForbidden();
});

it('costs few queries of its own', function () {
    $browser = Screen::as(Fx::staff(superAdmin: true));
    $theme = themesScreenCreate($browser, ['sa', 'ae']);

    // Measured 2026-11-11 (PR 8), a Super Admin's frame included (37: SeoRedirectsScreensTest says why): the list 40, 3 of
    // its own - the themes, their stores, their parts filled; a theme's page of two stores and one image 45, 8 of its own -
    // the theme, its stores, parts and images, the image's file and sizes (2), its linked campaigns, Translate's mark.
    // Content's target is 15 of its own (§4.2).
    expect([Screen::queries($browser, THEMES_SCREEN), Screen::queries($browser, THEMES_SCREEN."/{$theme}")])->toBe([40, 45]);
});
