<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentScreen as Screen;

use function Pest\Laravel\seed;

/*
| W1 · Home Page over HTTP (content.md §4.4, §1.4, §1.5): the sections and their JSON, a section's own page, filled and
| shown (refused while English is missing), ordered whole, removed and added back, the footer saved with both languages,
| who is refused, the menu entry and the queries. The rules are HomeAndFrameTest's.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour()->startOfSecond());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

const HOME_SCREEN = '/admin/content/home';

/** @param  list<string>  $stores */
function homeScreenEditor(array $stores = ['sa']): string
{
    return Fx::staffWith([ContentPermissions::HOMEPAGE_MANAGE], $stores, RoleLevel::Admin);
}

function homeScreenSection(string $type): string
{
    return (string) DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', $type)->value('id');
}

it('gives the sections in order with nothing to add, their JSON, and a section\'s own page', function () {
    $browser = Screen::as(homeScreenEditor(['sa', 'ae']));

    $browser->get(HOME_SCREEN.'?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Home/Index')
        ->where('tab', 'sections')
        ->has('sections', 8)
        ->where('sections.0.type', 'HERO')
        ->where('addable', [])
        ->where('footer.version', 0)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_home.title') && $words->has('catalog::admin.drag.instructions')));

    expect(array_keys($browser->get(HOME_SCREEN.'?store=sa', Screen::JSON)->assertOk()->json()))->toBe(['sections', 'addable']);

    $browser->get(HOME_SCREEN.'/'.homeScreenSection('PROMISES'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Home/Section')
        ->where('section.type', 'PROMISES')
        ->where('store.code', 'sa')
        ->has('icons', 12));
});

it('fills a section, refuses showing it without English, then shows it', function () {
    $browser = Screen::as(homeScreenEditor());
    $id = homeScreenSection('CATEGORIES');
    $first = (int) DB::table('content.home_sections')->where('id', $id)->value('version');

    $browser->post(HOME_SCREEN."/{$id}", ['store' => Fx::storeId('sa'), 'version' => $first, 'settings' => ['heading' => ['ar' => 'تسوق حسب الفئة', 'en' => ''], 'count' => 8]], Screen::fromPage(HOME_SCREEN."/{$id}"))
        ->assertRedirect(HOME_SCREEN.'?store=sa');

    $refused = $browser->post(HOME_SCREEN."/sections/{$id}/show", ['visible' => true], Screen::fromPage(HOME_SCREEN));
    // The spec's own words (§4.4 W1), in both languages.
    expect(Screen::errors($refused))->toBe(['form' => 'Add the English first.']);
    app()->setLocale('ar');
    expect(__('content::admin_home.sections.add_english'))->toBe('أضف النص الإنجليزي أولًا.');
    app()->setLocale('en');

    $version = (int) DB::table('content.home_sections')->where('id', $id)->value('version');
    $browser->post(HOME_SCREEN."/{$id}", ['store' => Fx::storeId('sa'), 'version' => $version, 'settings' => ['heading' => ['ar' => 'تسوق حسب الفئة', 'en' => 'Shop by category'], 'count' => 13]], Screen::fromPage(HOME_SCREEN."/{$id}"))
        ->assertRedirect();
    expect(Screen::errors($browser->post(HOME_SCREEN."/{$id}", ['store' => Fx::storeId('sa'), 'version' => $version, 'settings' => ['heading' => ['ar' => 'تسوق', 'en' => 'Shop'], 'count' => 13]], Screen::fromPage(HOME_SCREEN."/{$id}"))))->toHaveKey('count');

    $browser->post(HOME_SCREEN."/{$id}", ['store' => Fx::storeId('sa'), 'version' => $version, 'settings' => ['heading' => ['ar' => 'تسوق حسب الفئة', 'en' => 'Shop by category'], 'count' => 12]], Screen::fromPage(HOME_SCREEN."/{$id}"))->assertRedirect();
    $shown = $browser->post(HOME_SCREEN."/sections/{$id}/show", ['visible' => true], Screen::fromPage(HOME_SCREEN))->assertRedirect(HOME_SCREEN);

    expect(AdminBrowser::flashed($shown, 'status'))->toBe(__('content::admin_home.sections.shown'))
        ->and((bool) DB::table('content.home_sections')->where('id', $id)->value('is_visible'))->toBeTrue();
});

it('refuses a logo\'s missing Arabic name and its link each under its own box', function () {
    $browser = Screen::as(homeScreenEditor());
    $id = homeScreenSection('CLIENT_LOGOS');
    $version = (int) DB::table('content.home_sections')->where('id', $id)->value('version');
    $fill = static fn (array $logo): array => ['store' => Fx::storeId('sa'), 'version' => $version, 'settings' => ['heading' => ['ar' => '', 'en' => '']], 'logos' => [['mediaId' => Cx::image(mime: 'image/png'), 'nameAr' => 'أكمي', 'nameEn' => 'Acme', 'link' => '', ...$logo]]];

    expect(Screen::errors($browser->post(HOME_SCREEN."/{$id}", $fill(['nameAr' => '']), Screen::fromPage(HOME_SCREEN."/{$id}"))))->toHaveKey('logo_name_ar')
        ->and(Screen::errors($browser->post(HOME_SCREEN."/{$id}", $fill(['link' => '/a b']), Screen::fromPage(HOME_SCREEN."/{$id}"))))->toHaveKey('logo_link');
});

it('orders the sections only whole, removes one and adds it back', function () {
    $browser = Screen::as(homeScreenEditor());
    $ids = array_map(strval(...), DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->orderBy('position')->pluck('id')->all());

    $browser->post(HOME_SCREEN.'/sections/order', ['store' => Fx::storeId('sa'), 'ids' => array_reverse($ids)], Screen::fromPage(HOME_SCREEN))->assertRedirect();
    expect(DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->orderBy('position')->pluck('id')->all())->toBe(array_reverse($ids))
        ->and(Screen::errors($browser->post(HOME_SCREEN.'/sections/order', ['store' => Fx::storeId('sa'), 'ids' => [$ids[0]]], Screen::fromPage(HOME_SCREEN))))->toHaveKey('form');

    $browser->post(HOME_SCREEN.'/sections/'.homeScreenSection('POINTS').'/remove', [], Screen::fromPage(HOME_SCREEN))->assertRedirect();
    $browser->get(HOME_SCREEN.'?store=sa')->assertInertia(fn (AssertableInertia $page) => $page->has('sections', 7)->where('addable', ['POINTS']));

    $added = $browser->post(HOME_SCREEN.'/sections', ['store' => Fx::storeId('sa'), 'type' => 'POINTS'], Screen::fromPage(HOME_SCREEN));
    $added->assertRedirect(HOME_SCREEN.'/'.homeScreenSection('POINTS'));
});

it('saves the footer with both languages and refuses one alone under its box', function () {
    $browser = Screen::as(homeScreenEditor());
    $footer = [
        'store' => Fx::storeId('sa'), 'version' => 0, 'about_ar' => 'متجر الأدوات', 'about_en' => 'The tools shop', 'phone' => '+966500000000', 'email' => 'hello@example.test',
        'address_ar' => '', 'address_en' => '', 'copyright_ar' => '', 'copyright_en' => '',
        'columns' => [['titleAr' => 'المتجر', 'titleEn' => 'Shop', 'links' => [['textAr' => 'الكراسي', 'textEn' => 'Chairs', 'link' => '/categories/chairs']]]],
    ];

    $saved = $browser->post(HOME_SCREEN.'/footer', $footer, Screen::fromPage(HOME_SCREEN.'?tab=footer'))->assertRedirect(HOME_SCREEN.'?tab=footer');
    expect(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_home.footer.saved'))
        ->and(DB::table('content.footers')->where('store_id', Fx::storeId('sa'))->value('about_en'))->toBe('The tools shop');

    expect(Screen::errors($browser->post(HOME_SCREEN.'/footer', [...$footer, 'version' => 1, 'about_en' => ''], Screen::fromPage(HOME_SCREEN))))->toHaveKey('about_en');

    // A column's title, a link's text and a link are each refused under their own box, which the form puts under its row.
    $column = static fn (array $title, array $link): array => [...$footer, 'version' => 1, 'columns' => [[...$title, 'links' => [$link]]]];
    $link = ['textAr' => 'الكراسي', 'textEn' => 'Chairs', 'link' => '/categories/chairs'];
    expect(Screen::errors($browser->post(HOME_SCREEN.'/footer', $column(['titleAr' => '', 'titleEn' => 'Shop'], $link), Screen::fromPage(HOME_SCREEN))))->toHaveKey('column_title_ar')
        ->and(Screen::errors($browser->post(HOME_SCREEN.'/footer', $column(['titleAr' => 'المتجر', 'titleEn' => 'Shop'], [...$link, 'textEn' => '']), Screen::fromPage(HOME_SCREEN))))->toHaveKey('link_text_en')
        ->and(Screen::errors($browser->post(HOME_SCREEN.'/footer', $column(['titleAr' => 'المتجر', 'titleEn' => 'Shop'], [...$link, 'link' => '/chairs sale']), Screen::fromPage(HOME_SCREEN))))->toHaveKey('link');
});

it('refuses the page without content.homepage.manage, and another store\'s section is not found', function () {
    Screen::as(Fx::staffWith([ContentPermissions::BAR_MANAGE], ['sa']))->get(HOME_SCREEN)->assertForbidden();
    $other = (string) DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->value('id');

    Screen::as(homeScreenEditor(['sa']))->get(HOME_SCREEN."/{$other}")->assertNotFound();
});

it('offers the menu entry only to whoever may use it', function () {
    expect(Screen::menu(Screen::as(homeScreenEditor())))->toBe(['home_page']);
});

it('costs few queries of its own', function () {
    $browser = Screen::as(homeScreenEditor());

    // Measured 2026-11-10: 12 and 13, the panel's frame's 10 (AdminFrameQueryBudgetTest) and the page's own - the sections
    // and the footer; the logos band's section, its logos and their files. Content's target is 15 of its own (§4.2).
    expect([Screen::queries($browser, HOME_SCREEN.'?store=sa'), Screen::queries($browser, HOME_SCREEN.'/'.homeScreenSection('CLIENT_LOGOS'))])->toBe([12, 13]);
});
