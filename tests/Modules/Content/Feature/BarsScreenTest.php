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
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentScreen as Screen;

use function Pest\Laravel\seed;

/*
| W2 · Bars over HTTP (content.md §4.4, §1.5, §1.9): the page and its JSON, the announcement bar's save and its refusal
| under its box, smart bars added, changed and deleted, who is refused, the off store, the menu entry, the queries.
| The rules themselves are BannersPopupsSmartBarsTest's and HomeAndFrameTest's.
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

const BARS_SCREEN = '/admin/content/bars';

/** @param  list<string>  $stores */
function barsScreenEditor(array $stores = ['sa']): string
{
    return Fx::staffWith([ContentPermissions::BAR_MANAGE], $stores, RoleLevel::Admin);
}

it('gives a plain visit the page with its words, and JSON to whoever asks', function () {
    $browser = Screen::as(barsScreenEditor(['sa', 'ae']));

    $browser->get(BARS_SCREEN.'?store=sa&tab=smart')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Bars/Smart')
        ->where('storeCode', 'sa')
        ->where('storeTimezone', 'Asia/Riyadh')
        ->where('tab', 'smart')
        ->has('stores', 2)
        ->where('bar.isOn', false)
        ->where('bar.version', 0)
        ->has('smartBars', 0)
        ->where('translationReady', true)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_bars.title') && $words->has('content::admin_shared.translate.button') && $words->has('content::errors.stale_edit.detail')));

    expect(array_keys($browser->get(BARS_SCREEN.'?store=sa', Screen::JSON)->assertOk()->json()))->toBe(['bar', 'smartBars']);
});

it('saves the announcement bar from the page, and puts a refusal under its box', function () {
    $browser = Screen::as(barsScreenEditor());

    $saved = $browser->post(BARS_SCREEN.'/announcement', [
        'store' => Fx::storeId('sa'), 'version' => 0, 'is_on' => true, 'text_ar' => 'توصيل مجاني', 'text_en' => 'Free delivery', 'link' => '/categories/chairs', 'starts_at' => '', 'ends_at' => '',
    ], Screen::fromPage(BARS_SCREEN))->assertRedirect(BARS_SCREEN);

    expect(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_bars.bar.saved'))
        ->and(DB::table('content.bars')->where('store_id', Fx::storeId('sa'))->value('text_en'))->toBe('Free delivery');

    $refused = $browser->post(BARS_SCREEN.'/announcement', [
        'store' => Fx::storeId('sa'), 'version' => 1, 'is_on' => true, 'text_ar' => str_repeat('ب', 121), 'text_en' => 'Free delivery',
    ], Screen::fromPage(BARS_SCREEN));

    expect(Screen::errors($refused))->toHaveKey('text_ar')
        ->and(Screen::errors($refused))->not->toHaveKey('form');

    // Saved over a newer version: the form's error at the top, naming who and when.
    $stale = $browser->post(BARS_SCREEN.'/announcement', [
        'store' => Fx::storeId('sa'), 'version' => 0, 'is_on' => false, 'text_ar' => 'نص', 'text_en' => 'Text',
    ], Screen::fromPage(BARS_SCREEN));

    expect(Screen::errors($stale))->toHaveKey('form');
});

it('adds, changes and deletes a smart bar from the page', function () {
    $browser = Screen::as(barsScreenEditor());
    $category = Px::category();
    $fields = ['store' => Fx::storeId('sa'), 'scope' => 'CATEGORY', 'category_id' => $category, 'message_ar' => 'مطابخ بخصم', 'message_en' => 'Kitchens, on sale', 'link' => '', 'starts_at' => '', 'ends_at' => ''];

    $added = $browser->post(BARS_SCREEN.'/smart', $fields, Screen::fromPage(BARS_SCREEN.'?tab=smart'))->assertRedirect(BARS_SCREEN.'?tab=smart');
    $id = (string) DB::table('content.smart_bars')->value('id');

    expect(AdminBrowser::flashed($added, 'status'))->toBe(__('content::admin_bars.smart.saved'));

    $browser->post(BARS_SCREEN."/smart/{$id}", [...$fields, 'message_en' => 'Kitchens, half price'], Screen::fromPage(BARS_SCREEN))->assertRedirect();
    expect(DB::table('content.smart_bars')->where('id', $id)->value('message_en'))->toBe('Kitchens, half price');

    expect(Screen::errors($browser->post(BARS_SCREEN.'/smart', [...$fields, 'message_en' => ''], Screen::fromPage(BARS_SCREEN))))->toHaveKey('message_en');

    $deleted = $browser->post(BARS_SCREEN."/smart/{$id}/delete", [], Screen::fromPage(BARS_SCREEN))->assertRedirect();
    expect(AdminBrowser::flashed($deleted, 'status'))->toBe(__('content::admin_bars.smart.deleted'))
        ->and(DB::table('content.smart_bars')->count())->toBe(0);
});

it('refuses the page without content.bar.manage, and a store the person holds nothing in', function () {
    Screen::as(Fx::staffWith([ContentPermissions::PAGE_MANAGE], ['sa']))->get(BARS_SCREEN)->assertForbidden();
    Screen::as(barsScreenEditor(['sa']))->get(BARS_SCREEN.'?store=ae')->assertForbidden();
});

it('offers an off store only to whoever may switch stores, marked Off', function () {
    $editor = barsScreenEditor(['sa', 'ae']);
    Cx::switchOff('ae');

    Screen::as($editor)->get(BARS_SCREEN.'?store=ae')->assertForbidden();
    Screen::as(Fx::staff(superAdmin: true))->get(BARS_SCREEN.'?store=ae')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('storeCode', 'ae')
        ->where('stores', fn (Collection $stores): bool => $stores->firstWhere('code', 'ae')['isOn'] === false));
});

it('offers the menu entry only to whoever may use it', function () {
    expect(Screen::menu(Screen::as(barsScreenEditor())))->toBe(['bars'])
        ->and(Screen::menu(Screen::as(Fx::staffWith([ContentPermissions::SEO_MANAGE], ['sa']))))->not->toContain('bars');
});

it('costs few queries of its own', function () {
    Px::category();
    $browser = Screen::as(barsScreenEditor());

    // Measured 2026-11-10: 14, the panel's frame's 10 (AdminFrameQueryBudgetTest) and 4 of its own - the bar, the smart
    // bars, the categories on offer (two). Content's target is 15 of its own (§4.2).
    expect(Screen::queries($browser, BARS_SCREEN.'?store=sa'))->toBe(14);
});
