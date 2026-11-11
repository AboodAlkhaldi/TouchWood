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
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Content\Support\ContentScreen as Screen;

use function Pest\Laravel\seed;

/*
| W6 · Pop-Ups over HTTP (content.md §4.4, §1.10): the list and its JSON, New Pop-Up and a pop-up's page, Save Draft and
| Schedule, a clash under the dates, End Now, Back to Draft, Delete, the image uploaded over HTTP (lesson 117), who is
| refused, the menu entry and the queries. The rules are BannersPopupsSmartBarsTest's.
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

const POPUPS_SCREEN = '/admin/content/popups';

/** @param  list<string>  $stores */
function popupsScreenEditor(array $stores = ['sa']): string
{
    return Fx::staffWith([ContentPermissions::POPUP_MANAGE], $stores, RoleLevel::Admin);
}

/**
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function popupsScreenFields(array $with = []): array
{
    return [
        'store' => Fx::storeId('sa'), 'version' => 0, 'name' => 'Spring', 'title_ar' => 'عروض الربيع', 'title_en' => 'Spring offers',
        'text_ar' => 'خصومات على المطابخ', 'text_en' => 'Kitchens on sale', 'button_ar' => '', 'button_en' => '', 'link' => '',
        'delay_seconds' => '8', 'media_id' => '', 'starts_at' => '', 'ends_at' => '', 'intent' => 'draft', ...$with,
    ];
}

it('gives the list a plain visit and its Show More the JSON, and the form its words', function () {
    $popup = Cx::popup();
    $browser = Screen::as(popupsScreenEditor(['sa', 'ae']));

    $browser->get(POPUPS_SCREEN.'?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Popups/Index')
        ->where('storeCode', 'sa')
        ->has('stores', 2)
        ->has('popups', 1)
        ->where('popups.0.id', $popup)
        ->where('popups.0.state', 'DRAFT')
        ->where('next', null)
        ->where('translations', fn (Collection $words): bool => $words->has('content::admin_popups.title') && $words->has('content::admin_shared.state.LIVE')));

    expect(array_keys($browser->get(POPUPS_SCREEN.'?store=sa', Screen::JSON)->assertOk()->json()))->toBe(['popups', 'next']);

    $browser->get(POPUPS_SCREEN.'/new?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Popups/Form')
        ->where('store.code', 'sa')
        ->where('popup', null));

    $browser->get(POPUPS_SCREEN."/{$popup}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Content/Admin/Popups/Form')
        ->where('popup.id', $popup)
        ->where('storeTimezone', 'Asia/Riyadh'));
});

it('saves a draft, then schedules it, and refuses a clash under the dates', function () {
    $browser = Screen::as(popupsScreenEditor());

    $saved = $browser->post(POPUPS_SCREEN, popupsScreenFields(), Screen::fromPage(POPUPS_SCREEN.'/new'))->assertRedirect(POPUPS_SCREEN.'?store=sa');
    $id = (string) DB::table('content.popups')->value('id');

    expect(AdminBrowser::flashed($saved, 'status'))->toBe(__('content::admin_popups.saved'))
        ->and((bool) DB::table('content.popups')->where('id', $id)->value('is_scheduled'))->toBeFalse();

    $scheduled = $browser->post(POPUPS_SCREEN."/{$id}", popupsScreenFields(['version' => 1, 'intent' => 'schedule', 'starts_at' => Cx::typed(60)]), Screen::fromPage(POPUPS_SCREEN."/{$id}"))->assertRedirect(POPUPS_SCREEN.'?store=sa');

    expect(AdminBrowser::flashed($scheduled, 'status'))->toBe(__('content::admin_popups.scheduled'))
        ->and((bool) DB::table('content.popups')->where('id', $id)->value('is_scheduled'))->toBeTrue();

    // A second one over the same time: saved as a draft, its Schedule refused on its own page, under the dates.
    $clash = $browser->post(POPUPS_SCREEN, popupsScreenFields(['name' => 'Summer', 'intent' => 'schedule', 'starts_at' => Cx::typed(120)]), Screen::fromPage(POPUPS_SCREEN.'/new'));
    $other = (string) DB::table('content.popups')->where('name', 'Summer')->value('id');

    $clash->assertRedirect(POPUPS_SCREEN."/{$other}");
    expect(Screen::errors($clash))->toHaveKey('starts_at');

    // English missing at Schedule lands under the English box.
    $missing = $browser->post(POPUPS_SCREEN."/{$other}", popupsScreenFields(['version' => 1, 'name' => 'Summer', 'title_en' => '', 'intent' => 'schedule', 'starts_at' => Cx::typed(60 * 24 * 30)]), Screen::fromPage(POPUPS_SCREEN."/{$other}"));
    expect(Screen::errors($missing))->toHaveKey('title_en');
});

it('ends a live pop-up, takes a scheduled one back to draft, and deletes a draft', function () {
    $browser = Screen::as(popupsScreenEditor());
    $browser->post(POPUPS_SCREEN, popupsScreenFields(['intent' => 'schedule']), Screen::fromPage(POPUPS_SCREEN.'/new'))->assertRedirect();
    $id = (string) DB::table('content.popups')->value('id');

    $ended = $browser->post(POPUPS_SCREEN."/{$id}/end", [], Screen::fromPage(POPUPS_SCREEN))->assertRedirect(POPUPS_SCREEN);
    expect(AdminBrowser::flashed($ended, 'status'))->toBe(__('content::admin_popups.ended'));

    $browser->post(POPUPS_SCREEN, popupsScreenFields(['name' => 'Later', 'intent' => 'schedule', 'starts_at' => Cx::typed(600)]), Screen::fromPage(POPUPS_SCREEN.'/new'))->assertRedirect();
    $later = (string) DB::table('content.popups')->where('name', 'Later')->value('id');
    $browser->post(POPUPS_SCREEN."/{$later}/draft", [], Screen::fromPage(POPUPS_SCREEN))->assertRedirect();
    expect((bool) DB::table('content.popups')->where('id', $later)->value('is_scheduled'))->toBeFalse();

    $deleted = $browser->post(POPUPS_SCREEN."/{$later}/delete", [], Screen::fromPage(POPUPS_SCREEN))->assertRedirect();
    expect(AdminBrowser::flashed($deleted, 'status'))->toBe(__('content::admin_popups.deleted'))
        ->and(DB::table('content.popups')->where('id', $later)->exists())->toBeFalse();

    // The ended one is history: its delete is refused at the top of the page.
    expect(Screen::errors($browser->post(POPUPS_SCREEN."/{$id}/delete", [], Screen::fromPage(POPUPS_SCREEN))))->toHaveKey('form');
});

it('uploads an image under the pop-up\'s permission and answers it as its box shows it', function () {
    Storage::fake('local');
    Storage::fake('public');
    $browser = Screen::as(popupsScreenEditor());

    $answer = $browser->post('/admin/content/uploads/popup', ['store' => Fx::storeId('sa'), 'file' => UploadedFile::fake()->image('spring.jpg', 840, 440)], Screen::JSON)->assertCreated();

    expect($answer->json('name'))->toBe('spring.jpg')
        ->and($answer->json('width'))->toBe(840)
        ->and($answer->json('status'))->toBe('PENDING');

    // Not the pop-up's permission: refused.
    Screen::as(Fx::staffWith([ContentPermissions::BAR_MANAGE], ['sa']))->post('/admin/content/uploads/popup', ['store' => Fx::storeId('sa'), 'file' => UploadedFile::fake()->image('x.jpg')], Screen::JSON)->assertForbidden();
});

it('refuses the list without content.popup.manage, and another store\'s pop-up is not found', function () {
    $popup = Cx::popup([], 'ae');

    Screen::as(Fx::staffWith([ContentPermissions::BAR_MANAGE], ['sa']))->get(POPUPS_SCREEN)->assertForbidden();
    Screen::as(popupsScreenEditor(['sa']))->get(POPUPS_SCREEN."/{$popup}")->assertNotFound();
});

it('offers the menu entry only to whoever may use it', function () {
    expect(Screen::menu(Screen::as(popupsScreenEditor())))->toBe(['popups'])
        ->and(Screen::menu(Screen::as(Fx::staffWith([ContentPermissions::BAR_MANAGE], ['sa']))))->toBe(['bars']);
});

it('costs few queries of its own', function () {
    Cx::popup();
    $browser = Screen::as(popupsScreenEditor());

    // Measured 2026-11-10: 11 each, the panel's frame's 10 (AdminFrameQueryBudgetTest) and 1 of its own - the list; the
    // form's pop-up (its image none here). Content's target is 15 of its own (§4.2).
    // 2026-11-11 (PR 7b, P14): one more on a form and on the sections' list - Translate's unread mark (content.unread_translations).
    expect([Screen::queries($browser, POPUPS_SCREEN.'?store=sa'), Screen::queries($browser, POPUPS_SCREEN.'/'.DB::table('content.popups')->value('id'))])->toBe([11, 12]);
});
