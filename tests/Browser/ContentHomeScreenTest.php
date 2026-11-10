<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| W1 · Home Page in a real browser (content.md §4.4, §1.4): a section's heading typed with Translate filling its English,
| saved, then shown with the switch; Show refused, in words, on a section with its English missing; a section moved to
| the bottom sending the whole order; the Footer tab; Arabic, right to left, on a phone.
|
| Deliberately no RefreshDatabase: each run writes in `ae`, whose sections are put back hidden and empty first.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->update(['is_visible' => false, 'settings' => '{}']);
});

function contentHomeSection(string $type): string
{
    return (string) DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->where('type', $type)->value('id');
}

it('fills a section with Translate, shows it, refuses one without English, and moves one to the bottom', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::HOMEPAGE_MANAGE]);
    $projects = contentHomeSection('PROJECTS');

    $page->navigate("/admin/content/home/{$projects}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"section-form\"]') !== null"))->toBeTrue();
    $page->type('#heading_ar', "مشاريعنا {$run}")->click('[data-test="translate-heading"]');
    expect(browserSoon($page, "document.querySelector('#heading_en').value !== ''"))->toBeTrue();
    $page->click('[data-test="save-section"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/home' && document.body.textContent.includes('Section saved')"))->toBeTrue();

    // Its switch shows it.
    $page->click('[data-test="section-PROJECTS"] [data-test="show"]');
    expect(browserSoon($page, "document.body.textContent.includes('Section shown')"))->toBeTrue()
        ->and((bool) DB::table('content.home_sections')->where('id', $projects)->value('is_visible'))->toBeTrue();

    // Another with nothing typed: Show is refused, in words, and the switch stays off.
    $page->click('[data-test="section-POINTS"] [data-test="show"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"section-POINTS\"] [data-test=\"show\"]').getAttribute('data-state') === 'unchecked' && document.querySelector('[data-test=\"form-error\"]') !== null"))->toBeTrue()
        ->and((bool) DB::table('content.home_sections')->where('id', contentHomeSection('POINTS'))->value('is_visible'))->toBeFalse();

    // Move to Bottom sends the whole order.
    $page->click('[data-test="more-HERO"]')->click('[data-test="move-bottom"]');
    expect(browserSoon($page, "[...document.querySelectorAll('[data-test^=\"section-\"]')].at(-1)?.dataset.test === 'section-HERO'"))->toBeTrue();
    usleep(800_000);
    expect(DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->orderByDesc('position')->value('type'))->toBe('HERO');
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone, the sections and the footer', function () {
    $page = Cb::signIn([ContentPermissions::HOMEPAGE_MANAGE], 'ar');
    $page->resize(375, 812);

    $page->navigate('/admin/content/home?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"section-HERO\"]') !== null"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue();

    $page->navigate('/admin/content/home?store=ae&tab=footer', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"footer-form\"]') !== null"))->toBeTrue();
    $page->click('[data-test="add-column"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"column-0\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue();

    $page->navigate('/admin/content/home/'.contentHomeSection('HERO'), BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"section-form\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
