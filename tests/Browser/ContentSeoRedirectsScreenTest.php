<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;

use function Pest\Laravel\seed;

/*
| W10 · SEO and W11 · Redirects with W11b in a real browser (content.md §4.4, §1.14, §1.15): the SEO defaults saved, the
| pattern's sample following what is typed; a redirect line added in its dialog and found by the search; a checked sheet
| shown by its tabs, and **Apply Sheet** asking "Write N lines?" then writing them (lesson 87); Arabic, right to left, on
| a phone. The sheet is planted as the upload leaves it: the browser test's server takes no multipart (lesson 174), and
| the upload itself goes over HTTP in SeoRedirectsScreensTest.
|
| Deliberately no RefreshDatabase: every address carries the run's own number (lesson 174).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** A checked sheet as UploadRedirectSheetHandler leaves it: two new lines and one refused. */
function seoBrowserSheet(string $run, string $staffId): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('content.redirect_uploads')->insert([
        'id' => $id,
        'lines' => json_encode([
            ['line' => 2, 'old' => "/old-a-{$run}", 'new' => '/ae/ar', 'result' => 'NEW', 'reason' => null, 'typed_old' => "/old-a-{$run}", 'typed_new' => '/ae/ar'],
            ['line' => 3, 'old' => "/old-b-{$run}", 'new' => '/ae/en', 'result' => 'NEW', 'reason' => null, 'typed_old' => "/old-b-{$run}", 'typed_new' => '/ae/en'],
            ['line' => 4, 'old' => '/ae/taken', 'new' => '/ae/ar', 'result' => 'REFUSED', 'reason' => 'old_store', 'typed_old' => '/ae/taken', 'typed_new' => '/ae/ar'],
        ], JSON_THROW_ON_ERROR),
        'state' => 'CHECKED',
        'created_by' => $staffId,
        'created_at' => CarbonImmutable::now(),
    ]);

    return $id;
}

it('saves the SEO defaults with the pattern\'s sample following what is typed', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::SEO_MANAGE]);

    $page->navigate('/admin/content/seo?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"seo-form\"]') !== null"))->toBeTrue();
    $page->clear('#site_name_ar')->type('#site_name_ar', "تاتش وود {$run}")
        ->clear('#site_name_en')->type('#site_name_en', "TouchWood {$run}")
        ->clear('#title_pattern_ar')->type('#title_pattern_ar', ':title | :site')
        ->clear('#title_pattern_en')->type('#title_pattern_en', ':title - :site')
        ->clear('#description_ar')->type('#description_ar', 'أدوات وخشب')
        ->clear('#description_en')->type('#description_en', 'Tools and wood');
    expect(browserSoon($page, "document.querySelector('[data-test=\"pattern-sample\"]')?.textContent.includes('- TouchWood {$run}') === true"))->toBeTrue();
    $page->click('[data-test="save-seo"]');
    // The toast itself: the page's own words travel in its body too, so a bare text search would find them anyway.
    expect(browserSoon($page, "document.querySelector('[data-sonner-toast]')?.textContent.includes('SEO saved') === true"))->toBeTrue((string) $page->script("[...document.querySelectorAll('[role=alert], [data-slot=field-error]')].map((e) => e.textContent).join(' | ')"))
        ->and(DB::table('content.seo_defaults')->where('store_id', Fx::storeId('ae'))->value('site_name_en'))->toBe("TouchWood {$run}");
    $page->assertNoJavaScriptErrors();
});

it('adds a redirect line in its dialog, finds it, and applies a checked sheet after asking', function () {
    $run = Cb::run();
    $staff = Fx::staff(superAdmin: true);
    $page = Cb::signInAs($staff);

    $page->navigate('/admin/content/redirects', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"page-more\"]') !== null"))->toBeTrue();
    $page->click('[data-test="page-more"]')->click('[data-test="menu-add"]');
    expect(browserSoon($page, "document.querySelector('#old_path') !== null"))->toBeTrue();
    $page->type('#old_path', "/flash-{$run}")->type('#new_path', '/ae/ar')->click('[data-test="save-line"]');
    expect(browserSoon($page, "document.body.textContent.includes('/flash-{$run}')"))->toBeTrue()
        ->and(DB::table('content.redirects')->where('old_path', "/flash-{$run}")->value('new_path'))->toBe('/ae/ar');

    $sheet = seoBrowserSheet($run, $staff);
    $page->navigate("/admin/content/redirects/sheets/{$sheet}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"sheet-line-2\"]') !== null && document.querySelector('[data-test=\"tab-NEW\"]').textContent.includes('2')"))->toBeTrue();
    $page->click('[data-test="tab-REFUSED"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"sheet-line-4\"]') !== null && document.querySelector('[data-test=\"sheet-line-2\"]') === null"))->toBeTrue();

    $page->click('[data-test="apply-sheet"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-apply\"]') !== null && document.body.textContent.includes('Write 2 lines?')"))->toBeTrue()
        ->and(DB::table('content.redirects')->where('old_path', "/old-a-{$run}")->exists())->toBeFalse();
    $page->click('[data-test="confirm-apply"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/content/redirects'"))->toBeTrue()
        ->and(DB::table('content.redirects')->whereIn('old_path', ["/old-a-{$run}", "/old-b-{$run}"])->count())->toBe(2)
        ->and(DB::table('content.redirect_uploads')->where('id', $sheet)->value('state'))->toBe('APPLIED');
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone', function () {
    $run = Cb::run();
    $staff = Fx::staff(superAdmin: true);
    DB::table('access.staff_users')->where('id', $staff)->update(['locale' => 'ar']);
    $page = Cb::signInAs($staff);
    $page->resize(375, 812);

    foreach (['/admin/content/seo?store=ae', '/admin/content/redirects', '/admin/content/redirects/sheets/'.seoBrowserSheet($run, $staff)] as $address) {
        $page->navigate($address, BROWSER_SLOW_PAGE_LOAD);
        expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('main') !== null"))->toBeTrue()
            ->and(Cb::fitsPhone($page))->toBeTrue($address.' '.Cb::overflow($page))
            ->and(Cb::latinDigits($page))->toBeTrue();
    }

    $page->assertNoJavaScriptErrors();
});
