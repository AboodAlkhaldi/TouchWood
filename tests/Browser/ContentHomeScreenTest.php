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
    DB::table('content.footers')->where('store_id', Fx::storeId('ae'))->delete();

    // Back in the starting order: an earlier run moved one to the bottom. Two steps, as positions are unique per store.
    $types = ['HERO', 'PROMISES', 'CATEGORIES', 'BEST_SELLERS', 'PROJECTS', 'AUDIENCE', 'POINTS', 'CLIENT_LOGOS'];
    foreach ($types as $at => $type) {
        DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->where('type', $type)->update(['position' => 100 + $at]);
    }
    foreach ($types as $at => $type) {
        DB::table('content.home_sections')->where('store_id', Fx::storeId('ae'))->where('type', $type)->update(['position' => $at]);
    }
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
    expect(browserSoon($page, "window.location.pathname === '/admin/content/home' && document.body.textContent.includes('Section saved')"))->toBeTrue()
        // View Home Page, Edit as each section's own control, and the summary in the panel's language, not the Arabic.
        ->and($page->script("document.querySelector('[data-test=\"preview-home\"]') !== null && document.querySelector('[data-test=\"section-PROJECTS\"] [data-test=\"edit-section\"]')?.getAttribute('href') === '/admin/content/home/{$projects}'"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"section-PROJECTS\"]').textContent"))->toContain("[en] مشاريعنا {$run}"); // the fake translator's English

    // Its switch shows it.
    $page->click('[data-test="section-PROJECTS"] [data-test="show"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"section-PROJECTS\"] [data-test=\"show\"]')?.getAttribute('data-state') === 'checked' && document.body.textContent.includes('Section shown')"))->toBeTrue((string) $page->script("document.querySelector('main').textContent.slice(0, 400)"))
        ->and((bool) DB::table('content.home_sections')->where('id', $projects)->value('is_visible'))->toBeTrue(json_encode(DB::table('content.home_sections')->where('id', $projects)->first()) ?: '');

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
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->navigate('/admin/content/home?store=ae&tab=footer', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"footer-form\"]') !== null"))->toBeTrue();
    $page->click('[data-test="add-column"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"column-0\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));

    $page->navigate('/admin/content/home/'.contentHomeSection('HERO'), BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"section-form\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});

it('says a fact\'s refusal under that fact alone', function () {
    $page = Cb::signIn([ContentPermissions::HOMEPAGE_MANAGE]);

    $page->navigate('/admin/content/home/'.contentHomeSection('HERO'), BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"section-form\"]') !== null"))->toBeTrue();
    $page->click('[data-test="add-fact"]')->click('[data-test="add-fact"]');
    expect(browserSoon($page, "document.querySelector('#fact1_en') !== null"))->toBeTrue();
    $page->type('#fact0_ar', 'توصيل مجاني')->type('#fact1_en', 'Free returns');
    $page->click('[data-test="save-section"]');

    // The server refuses an English fact with no Arabic (`fact_ar`): said under the second fact, not under each, nor above.
    expect(browserSoon($page, "document.querySelector('#fact1_ar-error') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('#fact0_ar-error') === null && document.querySelector('[data-test=\"facts\"] > p.text-bad') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('checks the footer\'s links under their own rows, orders its columns, and saves it', function () {
    $page = Cb::signIn([ContentPermissions::HOMEPAGE_MANAGE]);

    $page->navigate('/admin/content/home?store=ae&tab=footer', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"footer-form\"]') !== null"))->toBeTrue();
    $page->click('[data-test="add-column"]')->click('[data-test="add-column"]');
    expect(browserSoon($page, "document.querySelector('#column1_ar') !== null"))->toBeTrue();
    $page->type('#column0_ar', 'المتجر')->type('#column0_en', 'Shop')->type('#column1_ar', 'المساعدة')->type('#column1_en', 'Help');
    $page->click('[data-test="add-link-0"]')->click('[data-test="add-link-0"]');
    expect(browserSoon($page, "document.querySelector('#column0link1_ar') !== null"))->toBeTrue();
    $page->type('#column0link0_ar', 'الكراسي')->type('#column0link0-address', '/categories/chairs')
        ->type('#column0link1_ar', 'الطاولات')->type('#column0link1_en', 'Tables')->type('#column0link1-address', '/categories/tables');

    // Saved with the first link's English missing: nothing is sent, and it is said under that link alone.
    $page->click('[data-test="save-footer"]');
    expect(browserSoon($page, "document.querySelector('#column0link0_en-error') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('#column0link1_en-error') === null && document.querySelector('#column0link0-address-error') === null"))->toBeTrue()
        ->and(DB::table('content.footers')->where('store_id', Fx::storeId('ae'))->exists())->toBeFalse();

    // A link the server would refuse (a space in it) is said as it is typed, under its own row.
    $page->type('#column0link0_en', 'Chairs')->type('#column0link1-address', '/tables sale');
    expect(browserSoon($page, "document.querySelector('#column0link1-address-error') !== null && document.querySelector('#column0link0_en-error') === null"))->toBeTrue()
        ->and($page->script("document.querySelector('#column0link0-address-error') === null"))->toBeTrue();
    $page->type('#column0link1-address', '/categories/tables');

    // Move to Bottom puts the first column last.
    $page->click('[data-test="column-0-more"]')->click('[data-test="column-0-move-bottom"]');
    expect(browserSoon($page, "document.querySelector('#column1_ar')?.value === 'المتجر'"))->toBeTrue();
    $page->click('[data-test="save-footer"]');
    expect(browserSoon($page, "document.body.textContent.includes('Footer saved')"))->toBeTrue();

    /** @var list<array{title: array{en: string}, links: list<array{link: string}>}> $columns */
    $columns = json_decode((string) DB::table('content.footers')->where('store_id', Fx::storeId('ae'))->value('columns'), true);
    expect(array_map(static fn (array $column): string => $column['title']['en'], $columns))->toBe(['Help', 'Shop'])
        ->and(array_map(static fn (array $link): string => $link['link'], $columns[1]['links']))->toBe(['/categories/chairs', '/categories/tables']);
    $page->assertNoJavaScriptErrors();
});
