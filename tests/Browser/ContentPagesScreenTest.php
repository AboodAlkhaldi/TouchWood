<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| W7 · Pages in a real browser (content.md §4.4, §1.11, §1.2): a page typed with the editor's marks - Bold written by its
| button - its preview following, saved as a draft, found under the Draft tab, published from its page; an item marked by
| an earlier Translate asks for the tick at Publish (P14); Arabic, right to left, on a phone with the editor as tabs.
|
| Deliberately no RefreshDatabase: every title carries the run's own number (lesson 174).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('writes a page with the editor and its preview, saves it as a draft and publishes it', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::PAGE_MANAGE]);

    $page->navigate('/admin/content/pages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"document-form\"]') !== null"))->toBeTrue();
    $page->type('#title_ar', "دليل التركيب {$run}")
        ->type('#title_en', "Fitting guide {$run}")
        ->type('#body_ar', "# التركيب\n\nيناسب كل خزانة.")
        ->type('#body_en', 'Fits every cabinet.');
    // Bold writes its marks around a word it fills in where nothing is selected; the preview draws it bold.
    $page->click('[data-test="body_en-bold"]');
    expect(browserSoon($page, "document.querySelector('#body_en').value.includes('**bold words**')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"body_ar-preview\"] h2')?.textContent === 'التركيب' && document.querySelector('[data-test=\"body_en-preview\"] strong')?.textContent === 'bold words'"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"address-en\"]').textContent.includes('/ae/en/pages/')"))->toBeTrue();

    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/pages' && document.body.textContent.includes('Page saved')"))->toBeTrue();
    $page->click('[data-test="tab-draft"]');
    $id = (string) DB::table('content.page_translations')->where('title', "Fitting guide {$run}")->value('page_id');
    expect(browserSoon($page, "document.querySelector('[data-test=\"document-{$id}\"] [data-test=\"state\"]')?.textContent === 'Draft'"))->toBeTrue();

    $page->click("[data-test=\"document-{$id}\"]");
    expect(browserSoon($page, "window.location.pathname === '/admin/content/pages/{$id}' && document.querySelector('[data-test=\"publish\"]')?.textContent === 'Publish Page'"))->toBeTrue();
    $page->click('[data-test="publish"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/pages' && document.body.textContent.includes('Page published')"))->toBeTrue()
        ->and(DB::table('content.pages')->where('id', $id)->value('status'))->toBe('PUBLISHED');
    $page->assertNoJavaScriptErrors();
});

it('asks for the tick at Publish on a page an earlier Translate left unread, and clears the mark with it', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::PAGE_MANAGE]);
    $page->navigate('/admin/content/pages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"document-form\"]') !== null"))->toBeTrue();
    $page->type('#title_ar', "صفحة {$run}")->type('#title_en', "Page {$run}")->type('#body_ar', 'نص')->type('#body_en', 'Text');
    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "document.body.textContent.includes('Page saved')"))->toBeTrue();
    $id = (string) DB::table('content.page_translations')->where('title', "Page {$run}")->value('page_id');
    // As an earlier session's Translate leaves it (P14): the item carries the mark.
    DB::table('content.unread_translations')->insert(['kind' => 'PAGE', 'item_id' => $id, 'store_id' => Fx::storeId('ae'), 'marked_at' => CarbonImmutable::now()]);

    $page->navigate("/admin/content/pages/{$id}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"publish\"]') !== null"))->toBeTrue();
    $page->click('[data-test="publish"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"read-tick\"]') !== null"))->toBeTrue();
    $page->click('[data-test="read-tick"]')->click('[data-test="read-confirm"]');
    expect(browserSoon($page, "document.body.textContent.includes('Page published')"))->toBeTrue()
        ->and(DB::table('content.unread_translations')->where('item_id', $id)->count())->toBe(0);
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone, the editor as Write and Preview', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::PAGE_MANAGE], 'ar');
    $page->resize(375, 812);

    $page->navigate('/admin/content/pages/new?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"document-form\"]') !== null"))->toBeTrue();
    $page->type('#title_ar', "صفحة طويلة العنوان جدا لا تنكسر على الهاتف {$run}")->type('#body_ar', "| المقاس | السعر |\n| 60 | 1200 |");
    expect(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page))
        // On a phone the preview is a tab of its own; its table scrolls inside its frame.
        ->and($page->script("getComputedStyle(document.querySelector('[data-test=\"body_ar-preview\"]')).display"))->toBe('none');
    $page->click('[data-test="body_ar-preview-tab"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"body_ar-preview\"] table') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->click('[data-test="save-draft"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/pages'"))->toBeTrue();
    $page->click('[data-test="tab-draft"]');
    expect(browserSoon($page, "document.querySelector('[data-test^=\"document-card-\"]') !== null"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});
