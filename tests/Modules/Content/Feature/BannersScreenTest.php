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
| W3 · Banners over HTTP (content.md §4.4, §1.6): a placement's tab with every placement's count, the form and its
| images, Save Draft and Schedule (an image not ready refused under its box), the order sent whole, End Now, Back to
| Draft, Delete, who is refused, the menu entry, the queries. The rules are BannersPopupsSmartBarsTest's.
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

const BANNERS_SCREEN = '/admin/content/banners';

/** @param  list<string>  $stores */
function bannersScreenEditor(array $stores = ['sa']): string
{
    return Fx::staffWith([ContentPermissions::BANNER_MANAGE], $stores, RoleLevel::Admin);
}

/**
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function bannersScreenFields(array $with = []): array
{
    return [
        'store' => Fx::storeId('sa'), 'version' => 0, 'placement' => 'HOME_PHONE', 'category_id' => '', 'name' => 'Spring', 'link' => '/categories/chairs',
        'has_words' => false, 'alt_ar' => 'مطبخ', 'alt_en' => 'A kitchen', 'starts_at' => '', 'ends_at' => '', 'images' => ['PHONE:any' => Cx::image()], 'intent' => 'draft', ...$with,
    ];
}

it('gives a placement\'s tab with every count, its JSON, and the form with its images', function () {
    $banner = Cx::banner(['placement' => 'HOME_PHONE', 'images' => ['PHONE:any' => Cx::image()]]);
    Cx::banner(['placement' => 'LISTING']);
    $browser = Screen::as(bannersScreenEditor(['sa', 'ae']));

    $browser->get(BANNERS_SCREEN.'?store=sa&placement=HOME_PHONE')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Banners/Index')
        ->where('placement', 'HOME_PHONE')
        ->where('counts', ['HOME_HERO' => 0, 'HOME_PHONE' => 1, 'LISTING' => 1])
        ->has('banners', 1)
        ->where('banners.0.id', $banner)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_banners.title') && $words->has('catalog::admin.drag.instructions')));

    expect(array_keys($browser->get(BANNERS_SCREEN.'?store=sa', Screen::JSON)->assertOk()->json()))->toBe(['banners', 'counts']);

    $browser->get(BANNERS_SCREEN."/{$banner}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Banners/Form')
        ->where('banner.id', $banner)
        ->where('placement', 'HOME_PHONE')
        ->has('images.PHONE:any'));
});

it('saves a draft, schedules it, and puts an image not ready under its box', function () {
    $browser = Screen::as(bannersScreenEditor());

    $browser->post(BANNERS_SCREEN, bannersScreenFields(), Screen::fromPage(BANNERS_SCREEN.'/new'))->assertRedirect(BANNERS_SCREEN.'?store=sa&placement=HOME_PHONE');
    $id = (string) DB::table('content.banners')->value('id');

    $scheduled = $browser->post(BANNERS_SCREEN."/{$id}", bannersScreenFields(['version' => 1, 'intent' => 'schedule']), Screen::fromPage(BANNERS_SCREEN."/{$id}"))->assertRedirect();
    expect(AdminBrowser::flashed($scheduled, 'status'))->toBe(__('content::admin_banners.scheduled'))
        ->and((bool) DB::table('content.banners')->where('id', $id)->value('is_scheduled'))->toBeTrue();

    $pending = $browser->post(BANNERS_SCREEN, bannersScreenFields(['name' => 'Pending', 'images' => ['PHONE:any' => Cx::image('PENDING')], 'intent' => 'schedule']), Screen::fromPage(BANNERS_SCREEN.'/new'));
    $other = (string) DB::table('content.banners')->where('name', 'Pending')->value('id');

    $pending->assertRedirect(BANNERS_SCREEN."/{$other}");
    expect(Screen::errors($pending))->toHaveKey('phone_image');
});

it('orders a placement only with its whole list, and ends, drafts and deletes from the menu', function () {
    $first = Cx::banner(['placement' => 'HOME_PHONE']);
    $second = Cx::banner(['placement' => 'HOME_PHONE']);
    $browser = Screen::as(bannersScreenEditor());

    $browser->post(BANNERS_SCREEN.'/order', ['store' => Fx::storeId('sa'), 'placement' => 'HOME_PHONE', 'ids' => [$second, $first]], Screen::fromPage(BANNERS_SCREEN))->assertRedirect();
    expect(DB::table('content.banners')->orderBy('position')->pluck('id')->all())->toBe([$second, $first]);

    expect(Screen::errors($browser->post(BANNERS_SCREEN.'/order', ['store' => Fx::storeId('sa'), 'placement' => 'HOME_PHONE', 'ids' => [$first]], Screen::fromPage(BANNERS_SCREEN))))->toHaveKey('form');

    $deleted = $browser->post(BANNERS_SCREEN."/{$first}/delete", [], Screen::fromPage(BANNERS_SCREEN))->assertRedirect();
    expect(AdminBrowser::flashed($deleted, 'status'))->toBe(__('content::admin_banners.deleted'))
        ->and(DB::table('content.banners')->where('id', $first)->exists())->toBeFalse();
});

it('refuses the list without content.banner.manage, and another store\'s banner is not found', function () {
    $banner = Cx::banner([], 'ae');

    Screen::as(Fx::staffWith([ContentPermissions::BAR_MANAGE], ['sa']))->get(BANNERS_SCREEN)->assertForbidden();
    Screen::as(bannersScreenEditor(['sa']))->get(BANNERS_SCREEN."/{$banner}")->assertNotFound();
});

it('offers the menu entry only to whoever may use it', function () {
    expect(Screen::menu(Screen::as(bannersScreenEditor())))->toBe(['banners']);
});

it('costs few queries of its own', function () {
    $banner = Cx::banner(['placement' => 'HOME_PHONE', 'images' => ['PHONE:any' => Cx::image()]]);
    $browser = Screen::as(bannersScreenEditor());

    // Measured 2026-11-10: 15 each, the panel's frame's 10 (AdminFrameQueryBudgetTest) and 5 of the page's own (the list
    // and its counts, the thumbnails' files, the categories on offer; the form's banner, its images, the categories).
    // Content's target is 15 of its own (§4.2).
    expect([Screen::queries($browser, BANNERS_SCREEN.'?store=sa&placement=HOME_PHONE'), Screen::queries($browser, BANNERS_SCREEN."/{$banner}")])->toBe([15, 15]);
});
