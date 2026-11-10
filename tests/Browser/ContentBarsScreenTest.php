<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| W2 · Bars in a real browser (content.md §4.4): the announcement bar switched on with Translate filling its English and
| the one tick before it reaches shoppers; a smart bar added on a category, then deleted; Arabic right to left and a phone.
|
| Deliberately no RefreshDatabase, as the other browser tests: each run writes in `ae` under its own number.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    DB::table('content.smart_bars')->where('store_id', Fx::storeId('ae'))->delete();
});

it('switches the bar on with its English translated, asking for the tick first', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::BAR_MANAGE]);

    $page->navigate('/admin/content/bars?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"bar-form\"]') !== null"))->toBeTrue();

    $page->click('[data-test="bar-on"]')
        ->type('#text_ar', "توصيل مجاني {$run}")
        ->click('[data-test="translate-text"]');
    // The fake translator answers at once; the box is filled and marked.
    expect(browserSoon($page, "document.querySelector('#text_en').value !== '' && document.body.textContent.includes('Translated, read it before publishing')"))->toBeTrue();

    $page->click('[data-test="save-bar"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"read-tick\"]') !== null"))->toBeTrue();
    $page->click('[data-test="read-tick"]')->click('[data-test="read-confirm"]');
    expect(browserSoon($page, "document.body.textContent.includes('Bar saved')"))->toBeTrue()
        ->and((bool) DB::table('content.bars')->where('store_id', Fx::storeId('ae'))->value('is_on'))->toBeTrue()
        ->and((string) DB::table('content.bars')->where('store_id', Fx::storeId('ae'))->value('text_ar'))->toBe("توصيل مجاني {$run}");
    $page->assertNoJavaScriptErrors();
});

it('adds a smart bar on a category and deletes it', function () {
    $run = Cb::run();
    $category = Px::category("Chairs{$run}");
    $page = Cb::signIn([ContentPermissions::BAR_MANAGE]);

    $page->navigate('/admin/content/bars?store=ae&tab=smart', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"smart-empty\"]') !== null"))->toBeTrue();

    $page->click('[data-test="add-smart-bar"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"smart-bar-form\"]') !== null"))->toBeTrue();
    $page->type('#message_ar', "خصم الكراسي {$run}")->type('#message_en', "Chairs on sale {$run}");
    $page->click('[data-test="category"]');
    expect(browserSoon($page, "document.querySelector('[cmdk-input]') !== null"))->toBeTrue();
    $page->type('[cmdk-input]', "Chairs{$run}");
    expect(browserSoon($page, "[...document.querySelectorAll('[cmdk-item]')].length === 1"))->toBeTrue();
    $page->script("(() => { document.querySelector('[cmdk-item]').click(); })()");
    $page->click('[data-test="save-smart-bar"]');

    expect(browserSoon($page, "document.body.textContent.includes('Smart bar saved')"))->toBeTrue()
        ->and(DB::table('content.smart_bars')->where('store_id', Fx::storeId('ae'))->value('category_id'))->toBe($category);
    $id = (string) DB::table('content.smart_bars')->where('store_id', Fx::storeId('ae'))->value('id');

    expect(browserSoon($page, "document.querySelector('[data-test=\"smart-{$id}\"]') !== null"))->toBeTrue();
    $page->click("[data-test=\"smart-{$id}\"] [data-test=\"more-{$id}\"]")->click('[data-test="delete-smart-bar"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-delete-smart-bar\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-delete-smart-bar"]');
    expect(browserSoon($page, "document.body.textContent.includes('Smart bar deleted') && document.querySelector('[data-test=\"smart-{$id}\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone with its rows as cards', function () {
    $run = Cb::run();
    $category = Px::category("Doors{$run}");
    DB::table('content.smart_bars')->insert([
        'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('ae'), 'scope' => 'CATEGORY', 'category_id' => $category, 'run_id' => null,
        'message_ar' => "رسالة طويلة جدا لا تنكسر بسهولة على الهاتف {$run}", 'message_en' => "A long message {$run}", 'link' => null,
        'starts_at' => null, 'ends_at' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $page = Cb::signIn([ContentPermissions::BAR_MANAGE], 'ar');
    $page->resize(375, 812);

    $page->navigate('/admin/content/bars?store=ae&tab=smart', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test^=\"smart-card-\"]') !== null"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue();

    $page->navigate('/admin/content/bars?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"bar-form\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
