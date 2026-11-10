<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Content\Application\ContentPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Content\Support\ContentScreen as Screen;

use function Pest\Laravel\seed;

/*
| W10 · SEO and W11 · Redirects with W11b over HTTP (content.md §4.4, §1.14, §1.15): the pages and their JSON, the SEO
| defaults saved and refused under their box, Rebuild Sitemap queued once, the share image uploaded under
| content.seo.manage; a redirect line added, refused under its box, changed, reset, deleted; Check Addresses; a sheet
| uploaded over HTTP, shown line by line, applied; who is refused, the menu entries, the queries. The rules are
| SeoDefaultsTest's, RedirectLinesTest's and RedirectSheetTest's.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour()->startOfSecond());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, string>  $with
 * @return array<string, string>
 */
function seoScreenFields(array $with = []): array
{
    return [
        'store' => Fx::storeId('sa'), 'site_name_ar' => 'تاتش وود', 'site_name_en' => 'TouchWood', 'title_pattern_ar' => ':title | :site', 'title_pattern_en' => ':title | :site',
        'description_ar' => 'أدوات', 'description_en' => 'Tools', 'share_media_id' => '', 'robots_extra' => "/brands/old\n/x", ...$with,
    ];
}

it('gives the SEO page its defaults and sitemap, saves them, and refuses a robots line under its box', function () {
    $browser = Screen::as(Fx::staffWith([ContentPermissions::SEO_MANAGE], ['sa', 'ae'], RoleLevel::Admin));

    $browser->get('/admin/content/seo?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Seo')
        ->where('storeCode', 'sa')
        ->where('sitemapUrls', 0)
        ->where('sitemapAddress', url('/sa/sitemap.xml'))
        ->where('rebuildWaits', false)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_seo.title')));

    $saved = $browser->post('/admin/content/seo', seoScreenFields(), Screen::fromPage('/admin/content/seo'))->assertRedirect('/admin/content/seo');
    expect(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_seo.saved'))
        ->and(DB::table('content.seo_defaults')->where('store_id', Fx::storeId('sa'))->value('site_name_en'))->toBe('TouchWood');

    expect(Screen::errors($browser->post('/admin/content/seo', seoScreenFields(['robots_extra' => 'no-slash']), Screen::fromPage('/admin/content/seo'))))->toHaveKey('robots_extra')
        ->and(Screen::errors($browser->post('/admin/content/seo', seoScreenFields(['site_name_en' => '']), Screen::fromPage('/admin/content/seo'))))->toHaveKey('site_name_en');
});

it('queues one sitemap rebuild, and says so for a second', function () {
    $browser = Screen::as(Fx::staffWith([ContentPermissions::SEO_MANAGE], ['sa'], RoleLevel::Admin));

    expect(AdminBrowser::flashed($browser->post('/admin/content/seo/sitemap', ['store' => Fx::storeId('sa')], Screen::fromPage('/admin/content/seo')), 'status'))->toBe(__('content::admin_seo.sitemap.queued'))
        ->and(AdminBrowser::flashed($browser->post('/admin/content/seo/sitemap', ['store' => Fx::storeId('sa')], Screen::fromPage('/admin/content/seo')), 'status'))->toBe(__('content::admin_seo.sitemap.waits'));

    $browser->get('/admin/content/seo?store=sa')->assertInertia(fn (AssertableInertia $page) => $page->where('rebuildWaits', true));
});

it('uploads a share image under content.seo.manage only', function () {
    Storage::fake('local');
    Storage::fake('public');

    Screen::as(Fx::staffWith([ContentPermissions::SEO_MANAGE], ['sa'], RoleLevel::Admin))
        ->post('/admin/content/uploads/seo', ['store' => Fx::storeId('sa'), 'file' => UploadedFile::fake()->image('share.jpg', 1200, 630)], Screen::JSON)
        ->assertCreated();
    Screen::as(Fx::staffWith([ContentPermissions::BAR_MANAGE], ['sa'], RoleLevel::Admin))
        ->post('/admin/content/uploads/seo', ['store' => Fx::storeId('sa'), 'file' => UploadedFile::fake()->image('share.jpg')], Screen::JSON)
        ->assertForbidden();
});

it('adds, refuses, changes, resets and deletes a redirect line, and queues the check', function () {
    $browser = Screen::as(Fx::staff(superAdmin: true));

    $browser->post('/admin/content/redirects', ['old_path' => '/flash-deals', 'new_path' => '/sa/ar'], Screen::fromPage('/admin/content/redirects'))->assertRedirect('/admin/content/redirects');
    expect(DB::table('content.redirects')->where('old_path', '/flash-deals')->value('new_path'))->toBe('/sa/ar')
        ->and(Screen::errors($browser->post('/admin/content/redirects', ['old_path' => '/sa/x', 'new_path' => '/sa/ar'], Screen::fromPage('/admin/content/redirects'))))->toHaveKey('old_path')
        ->and(Screen::errors($browser->post('/admin/content/redirects', ['old_path' => '/other', 'new_path' => '/nowhere'], Screen::fromPage('/admin/content/redirects'))))->toHaveKey('new_path');

    $browser->get('/admin/content/redirects')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Redirects/Index')
        ->has('rows', 1)
        ->where('rows.0.oldPath', '/flash-deals')
        ->where('checkWaits', false));
    expect(array_keys($browser->get('/admin/content/redirects', Screen::JSON)->json()))->toBe(['rows', 'next']);

    $browser->post('/admin/content/redirects/change', ['line' => '/flash-deals', 'old_path' => '/flash-deals', 'new_path' => '/sa/en'], Screen::fromPage('/admin/content/redirects'))->assertRedirect();
    DB::table('content.redirects')->where('old_path', '/flash-deals')->update(['visits' => 5]);
    $browser->post('/admin/content/redirects/reset', ['line' => '/flash-deals'], Screen::fromPage('/admin/content/redirects'))->assertRedirect();
    expect(DB::table('content.redirects')->where('old_path', '/flash-deals')->first(['new_path', 'visits']))->toEqual((object) ['new_path' => '/sa/en', 'visits' => 0]);

    expect(AdminBrowser::flashed($browser->post('/admin/content/redirects/check', [], Screen::fromPage('/admin/content/redirects')), 'status'))->toBe(__('content::admin_redirects.check.queued'));

    $browser->post('/admin/content/redirects/delete', ['line' => '/flash-deals'], Screen::fromPage('/admin/content/redirects'))->assertRedirect();
    expect(DB::table('content.redirects')->count())->toBe(0);
});

it('uploads a sheet over HTTP, shows it line by line, applies it, and refuses one that is not a sheet', function () {
    Storage::fake('local');
    $browser = Screen::as(Fx::staff(superAdmin: true));
    $csv = "Old Address,New Address\n/old-a,/sa/ar\n/sa/taken,/sa/ar\n";

    $answer = $browser->post('/admin/content/redirects/sheets', ['sheet' => UploadedFile::fake()->createWithContent('lines.csv', $csv)], Screen::fromPage('/admin/content/redirects'));
    $upload = (string) DB::table('content.redirect_uploads')->value('id');
    $answer->assertRedirect("/admin/content/redirects/sheets/{$upload}");

    $browser->get("/admin/content/redirects/sheets/{$upload}?tab=REFUSED")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Redirects/Sheet')
        ->where('tab', 'REFUSED')
        ->where('counts.NEW', 1)
        ->where('counts.REFUSED', 1)
        ->where('applied', false));
    expect(DB::table('content.redirects')->count())->toBe(0);

    $applied = $browser->post("/admin/content/redirects/sheets/{$upload}/apply", [], Screen::fromPage("/admin/content/redirects/sheets/{$upload}"))->assertRedirect('/admin/content/redirects');
    expect(AdminBrowser::flashed($applied, 'status'))->toBe(__('content::admin_redirects.sheet.applied', ['added' => 1, 'changed' => 0]))
        ->and(DB::table('content.redirects')->pluck('old_path')->all())->toBe(['/old-a']);

    expect(Screen::errors($browser->post('/admin/content/redirects/sheets', ['sheet' => UploadedFile::fake()->create('notes.pdf', 10)], Screen::fromPage('/admin/content/redirects'))))->toHaveKey('form');
});

it('refuses redirects to anyone but a Super Admin, and SEO without its job', function () {
    Screen::as(Fx::staffWith([ContentPermissions::SEO_MANAGE], ['sa'], RoleLevel::Admin))->get('/admin/content/redirects')->assertForbidden();
    Screen::as(Fx::staffWith([ContentPermissions::BAR_MANAGE], ['sa'], RoleLevel::Admin))->get('/admin/content/seo')->assertForbidden();
});

it('offers the menu entries only to whoever may use them', function () {
    expect(Screen::menu(Screen::as(Fx::staffWith([ContentPermissions::SEO_MANAGE], ['sa'], RoleLevel::Admin))))->toBe(['seo'])
        ->and(Screen::menu(Screen::as(Fx::staff(superAdmin: true))))->toContain('redirects');
});

it('costs few queries of its own', function () {
    $seo = Screen::as(Fx::staffWith([ContentPermissions::SEO_MANAGE], ['sa'], RoleLevel::Admin));
    $redirects = Screen::as(Fx::staff(superAdmin: true));

    // Measured 2026-11-11, the panel's frame included (AdminFrameQueryBudgetTest). Content's target is 15 of its own.
    expect([Screen::queries($seo, '/admin/content/seo?store=sa'), Screen::queries($redirects, '/admin/content/redirects')])->toBe([0, 0]);
});
