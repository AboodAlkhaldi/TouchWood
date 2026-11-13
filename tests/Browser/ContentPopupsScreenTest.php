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
| W6 · Pop-Ups in a real browser (content.md §4.4, §1.10): a new pop-up typed, its preview following the words, saved
| as a draft and found on the list; then scheduled from its page and ended from the list's menu; Arabic, right to left,
| on a phone with its rows as cards.
|
| Deliberately no RefreshDatabase: each run writes in `ae`, whose pop-ups are cleared first (one live at a time).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    DB::table('content.popups')->where('store_id', Fx::storeId('ae'))->delete();
});

it('writes a pop-up with its preview, saves it as a draft, schedules it and ends it', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::POPUP_MANAGE]);

    $page->navigate('/admin/content/popups/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"popup-form\"]') !== null"))->toBeTrue();
    $page->type('#name', "Spring {$run}")
        ->type('#title_ar', "عروض الربيع {$run}")
        ->type('#title_en', "Spring offers {$run}")
        ->type('#text_ar', 'خصومات على المطابخ')
        ->type('#text_en', 'Kitchens on sale');
    // The preview follows what is typed, at phone size first, then at a computer's.
    expect(browserSoon($page, "document.querySelector('[data-test=\"popup-preview\"]').textContent.includes('Spring offers {$run}')"))->toBeTrue();
    $page->click('[data-test="preview-desktop"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"popup-preview\"] [role=\"tabpanel\"][data-state=\"active\"]')?.textContent.includes('Kitchens on sale') === true"))->toBeTrue();

    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/popups' && document.body.textContent.includes('Pop-up saved')"))->toBeTrue();
    $id = (string) DB::table('content.popups')->where('name', "Spring {$run}")->value('id');
    expect(browserSoon($page, "document.querySelector('[data-test=\"popup-{$id}\"] [data-test=\"state\"]')?.textContent === 'Draft'"))->toBeTrue();

    // The whole row opens it; Schedule from its page, starting now.
    $page->click("[data-test=\"popup-{$id}\"]");
    expect(browserSoon($page, "window.location.pathname === '/admin/content/popups/{$id}' && document.querySelector('[data-test=\"schedule\"]') !== null"))->toBeTrue();
    $page->click('[data-test="schedule"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/popups' && document.body.textContent.includes('Pop-up scheduled')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"popup-{$id}\"] [data-test=\"state\"]')?.textContent === 'Live'"))->toBeTrue();

    $page->click("[data-test=\"popup-{$id}\"] [data-test=\"more-{$id}\"]")->click('[data-test="menu-end"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-end\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-end"]');
    expect(browserSoon($page, "document.body.textContent.includes('Pop-up ended') && document.querySelector('[data-test=\"popup-{$id}\"] [data-test=\"state\"]')?.textContent === 'Ended'"))->toBeTrue();

    // Ended, it is history: its page shows it, read only, with neither Schedule nor Save Draft.
    $page->click("[data-test=\"popup-{$id}\"]");
    expect(browserSoon($page, "window.location.pathname === '/admin/content/popups/{$id}' && document.querySelector('[data-test=\"ended-note\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"schedule\"]') === null && document.querySelector('[data-test=\"save-draft\"]') === null && document.querySelector('#name').matches(':disabled') && document.querySelector('#title_en').matches(':disabled')"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone, the list as cards and the form under its preview', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::POPUP_MANAGE], 'ar');
    $page->resize(375, 812);

    $page->navigate('/admin/content/popups/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"popup-form\"]') !== null"))->toBeTrue();
    $page->type('#name', "Phone {$run}")->type('#title_ar', "نافذة طويلة جدا لا تنكسر بسهولة على الهاتف {$run}")->type('#text_ar', 'نص');
    expect(Cb::fitsPhone($page))->toBeTrue();
    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/popups' && document.querySelector('[data-test^=\"popup-card-\"]') !== null"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page))
        // A card opens from the keyboard too: its title is a real link to the pop-up's page.
        ->and($page->script("document.querySelector('[data-test=\"popup-open-card\"]').tagName"))->toBe('A');
    $page->assertNoJavaScriptErrors();
});
