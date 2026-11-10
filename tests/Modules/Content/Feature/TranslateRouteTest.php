<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Content\Application\ContentPermissions;
use Modules\Platform\Infrastructure\External\FakeTextTranslator;
use Modules\Platform\Public\Enums\TranslationFailure;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\postJson;
use function Pest\Laravel\seed;

/*
| Translate over HTTP (content.md §1.3, §4.3): `POST /admin/content/translate` behind the panel's door,
| answered in JSON - each box's English, or the problem document in the reason's words. The use case has
| its own tests (TranslateTextTest); these are about the route.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    // Pinned an hour behind the real clock, never to a fixed date (lesson 221).
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour()->startOfSecond());
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Signed in through the panel's door as this staff member. */
function translateRouteAs(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @var array<string, string> */
const TRANSLATE_ROUTE_JSON = ['HTTP_ACCEPT' => 'application/json'];

it('answers each box\'s English, and how many blocks lost their marks', function () {
    $browser = translateRouteAs(Fx::staffWith([ContentPermissions::PAGE_MANAGE], ['sa']));

    $browser->post('/admin/content/translate', [
        'kind' => 'page',
        'store' => Fx::storeId('sa'),
        'boxes' => [['text' => 'عنوان', 'rich' => '0'], ['text' => 'فقرة **عريضة**', 'rich' => '1']],
    ], TRANSLATE_ROUTE_JSON)
        ->assertOk()
        ->assertExactJson(['texts' => ['[en] عنوان', '[en] فقرة عريضة'], 'lostMarks' => [0, 1]]);
});

it('says a failure in its own words, as a problem document', function () {
    $browser = translateRouteAs(Fx::staffWith([ContentPermissions::PAGE_MANAGE], ['sa']));
    app(FakeTextTranslator::class)->failNext(TranslationFailure::Quota);

    $browser->post('/admin/content/translate', ['kind' => 'page', 'store' => Fx::storeId('sa'), 'boxes' => [['text' => 'عنوان']]], TRANSLATE_ROUTE_JSON)
        ->assertStatus(409)
        ->assertJsonPath('type', 'content.translation_unavailable')
        ->assertJsonPath('detail', 'Failed to translate: this month\'s allowance is used up. Type the English instead.');
});

it('refuses a kind the person may not edit there', function () {
    $browser = translateRouteAs(Fx::staffWith([ContentPermissions::BANNER_MANAGE], ['sa']));

    $browser->post('/admin/content/translate', ['kind' => 'page', 'store' => Fx::storeId('sa'), 'boxes' => [['text' => 'عنوان']]], TRANSLATE_ROUTE_JSON)
        ->assertForbidden();

    expect(app(FakeTextTranslator::class)->calls())->toBe([]);
});

it('refuses boxes it cannot read, naming the line of a rich one written wrong', function () {
    $browser = translateRouteAs(Fx::staffWith([ContentPermissions::PAGE_MANAGE], ['sa']));
    $store = Fx::storeId('sa');

    $browser->post('/admin/content/translate', ['kind' => 'page', 'store' => $store, 'boxes' => 'text'], TRANSLATE_ROUTE_JSON)->assertStatus(422);
    $browser->post('/admin/content/translate', ['kind' => 'page', 'store' => $store, 'boxes' => [['text' => 'x', 'rich' => 'maybe']]], TRANSLATE_ROUTE_JSON)->assertStatus(422);
    $browser->post('/admin/content/translate', ['kind' => 'page', 'store' => $store, 'boxes' => array_fill(0, 51, ['text' => 'x'])], TRANSLATE_ROUTE_JSON)->assertStatus(422);
    $browser->post('/admin/content/translate', ['kind' => 'page', 'store' => $store, 'boxes' => [['text' => "سطر\n\n[x](javascript:alert(1))", 'rich' => '1']]], TRANSLATE_ROUTE_JSON)
        ->assertStatus(422)
        ->assertJsonPath('detail', 'Line 3: a link goes to a page of the shop, such as /categories/chairs, or to a full https:// address.');

    expect(app(FakeTextTranslator::class)->calls())->toBe([]);
});

it('is behind the panel\'s door', function () {
    postJson('/admin/content/translate', ['kind' => 'page', 'boxes' => [['text' => 'x']]])->assertUnauthorized();

    expect(app(FakeTextTranslator::class)->calls())->toBe([]);
});
