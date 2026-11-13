<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentBrowser as Cb;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| W3 · Banners in a real browser (content.md §4.4, §1.6): the placement's tabs with their counts, Move to Bottom from a
| row's menu sending the whole order, the whole row opening the banner's page, the words tick asking for an Arabic and an
| English image, a draft deleted; Arabic, right to left, on a phone with the rows as cards.
|
| Deliberately no RefreshDatabase: each run writes in `ae`, whose banners are cleared first.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    DB::table('content.banners')->where('store_id', Fx::storeId('ae'))->delete();
});

it('moves a banner to the bottom, opens one, and deletes a draft', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::BANNER_MANAGE]);
    $first = Fx::asSystem(fn (): string => Cx::banner(['name' => "First {$run}"], 'ae'));
    $second = Fx::asSystem(fn (): string => Cx::banner(['name' => "Second {$run}"], 'ae'));

    $page->navigate('/admin/content/banners?store=ae&placement=HOME_PHONE', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"banner-{$second}\"]') !== null"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"tab-HOME_PHONE\"]').textContent"))->toContain('2');

    $page->click("[data-test=\"banner-{$first}\"] [data-test=\"more-{$first}\"]")->click('[data-test="move-bottom"]');
    // The row moves at once, and the whole order reaches the server.
    expect(browserSoon($page, "(() => { const rows = [...document.querySelectorAll('tbody [data-test^=\"banner-\"]')].map((row) => row.dataset.test); return rows[0] === 'banner-{$second}' && rows[1] === 'banner-{$first}'; })()"))->toBeTrue();
    usleep(800_000);
    expect(DB::table('content.banners')->where('store_id', Fx::storeId('ae'))->orderBy('position')->pluck('id')->all())->toBe([$second, $first]);

    // The whole row opens the banner; the words tick asks for an image per language.
    $page->click("[data-test=\"banner-{$second}\"]");
    expect(browserSoon($page, "window.location.pathname === '/admin/content/banners/{$second}' && document.querySelector('[data-test=\"image-phone_image\"]') !== null"))->toBeTrue();
    $page->click('[data-test="has-words"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"image-phone_image_ar\"]') !== null && document.querySelector('[data-test=\"image-phone_image_en\"]') !== null"))->toBeTrue();

    $page->navigate('/admin/content/banners?store=ae&placement=HOME_PHONE', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"banner-{$first}\"]') !== null"))->toBeTrue();
    $page->click("[data-test=\"banner-{$first}\"] [data-test=\"more-{$first}\"]")->click('[data-test="menu-delete"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-delete\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-delete"]');
    expect(browserSoon($page, "document.body.textContent.includes('Banner deleted') && document.querySelector('[data-test=\"banner-{$first}\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone with its rows as cards', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::BANNER_MANAGE], 'ar');
    $banner = Fx::asSystem(fn (): string => Cx::banner(['name' => "Banner with a long name that does not break {$run}"], 'ae'));
    $page->resize(375, 812);

    $page->navigate('/admin/content/banners?store=ae&placement=HOME_PHONE', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"banner-card-{$banner}\"]') !== null"))->toBeTrue()
        ->and(Cb::latinDigits($page))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page))
        // A card opens from the keyboard too: its title is a real link to the banner's page.
        ->and($page->script("document.querySelector('[data-test=\"banner-open-card\"]').tagName"))->toBe('A');

    $page->navigate("/admin/content/banners/{$banner}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"banner-form\"]') !== null"))->toBeTrue()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});

it('shows an ended banner read only, and warns of a phone image only under twice the size it is shown at', function () {
    $run = Cb::run();
    $page = Cb::signIn([ContentPermissions::BANNER_MANAGE]);
    // 347 x 174 on a phone (§1.6), so 694 x 348 at double density: 700 x 350 is enough, 600 x 300 is not.
    $sharp = Cx::image();
    $soft = Cx::image();
    DB::table('platform.media')->where('id', $sharp)->update(['width' => 700, 'height' => 350]);
    DB::table('platform.media')->where('id', $soft)->update(['width' => 600, 'height' => 300]);
    $ended = Fx::asSystem(fn (): string => Cx::banner(['name' => "Ended {$run}", 'images' => ['PHONE:any' => $sharp]], 'ae'));
    $draft = Fx::asSystem(fn (): string => Cx::banner(['name' => "Soft {$run}", 'images' => ['PHONE:any' => $soft]], 'ae'));
    DB::table('content.banners')->where('id', $ended)->update(['is_scheduled' => true, 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

    $page->navigate("/admin/content/banners/{$ended}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"ended-note\"]') !== null && document.querySelector('[data-test=\"image-phone_image\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"schedule\"]') === null && document.querySelector('[data-test=\"save-draft\"]') === null && document.querySelector('#name').matches(':disabled') && document.querySelector('[data-test=\"image-phone_image-choose\"]').matches(':disabled')"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"image-phone_image-small\"]') === null"))->toBeTrue();

    $page->navigate("/admin/content/banners/{$draft}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"image-phone_image-small\"]')?.textContent.includes('694 x 348') === true"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"schedule\"]') !== null && document.querySelector('[data-test=\"ended-note\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
