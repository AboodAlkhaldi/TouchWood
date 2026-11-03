<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Public\PlatformPermissions as Platform;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\OpsFixtures as Ox;

use function Pest\Laravel\seed;

/*
| The bells' place in the frames and their full pages (ops.md §4.15 O1, O2): the number with each page, for
| staff and a signed-in customer only, never on a partial reload; a plain visit gets the page and a JSON
| read today's answer; reading a page marks nothing.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
});

function bellScreensStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.11.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');
    $browser->setCookie(HandleInertiaRequests::LOCALE_COOKIE, 'en');

    return $browser;
}

function bellScreensCustomer(string $email = 'sara@example.test'): AdminBrowser
{
    $browser = new AdminBrowser('10.9.12.'.random_int(20, 250));
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

/**
 * The props of a partial reload asking for the page's list only.
 *
 * @return array<string, mixed>
 */
function bellScreensPartial(AdminBrowser $browser, string $url, string $component): array
{
    /** @var array{props: array<string, mixed>} $answer */
    $answer = $browser->get($url, [
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_INERTIA_VERSION' => (string) (app(HandleInertiaRequests::class)->version(request()) ?? ''),
        'HTTP_X_INERTIA_PARTIAL_COMPONENT' => $component,
        'HTTP_X_INERTIA_PARTIAL_DATA' => 'inbox',
    ])->assertOk()->json();

    return $answer['props'];
}

it('carries the panel bell with a page: the number, whether rows name a store, its words', function () {
    $one = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);
    Ox::staffEntry($one);
    Ox::staffEntry($one);

    bellScreensStaff($one)->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('panelFrame.ops.unread', 2)
        ->where('panelFrame.ops.manyStores', false)
        ->where('panelFrame.ops.words.title', 'Notifications')
        ->where('panelFrame.ops.words.name_unread', 'Notifications, :count unread'));

    bellScreensStaff(Fx::staffWith([Platform::AUDIT_VIEW], ['sa', 'ae']))->get('/admin')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('panelFrame.ops.unread', 0)->where('panelFrame.ops.manyStores', true));
});

it('says two stores from every store the bell spans, an off one too, not the menu\'s list', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa', 'ae']);
    DB::table('platform.stores')->where('code', 'ae')->update(['is_active' => false]);
    app('cache')->flush();

    bellScreensStaff($staffId)->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('panelFrame.ops.manyStores', true)
        ->has('viewStores', 1));
});

it('gives nothing to the panel\'s sign-in page, and draws a bell for someone with no store', function () {
    (new AdminBrowser('10.9.11.10'))->get('/admin/sign-in')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('panelFrame', []));

    bellScreensStaff(Fx::staff())->get('/admin')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('panelFrame.ops.unread', 0)->where('panelFrame.ops.manyStores', false));
});

it('asks for the panel bell on no partial reload', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);
    Ox::staffEntry($staffId);

    $props = bellScreensPartial(bellScreensStaff($staffId), '/admin/notifications', 'Ops/Admin/Notifications');

    expect($props)->toHaveKey('inbox')
        ->and(array_key_exists('panelFrame', $props))->toBeFalse();
});

it('opens the panel\'s full page on a plain visit, marks nothing, and keeps the JSON beside it', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);
    $entry = Ox::staffEntry($staffId);
    $browser = bellScreensStaff($staffId);

    $browser->get('/admin/notifications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/Notifications')
        ->where('inbox.unread', 1)
        ->where('inbox.next', null)
        ->where('inbox.entries.0.id', $entry)
        ->where('inbox.entries.0.read', false)
        ->where('translations', fn (Collection $words): bool => $words->has('admin.home.title')));

    $json = $browser->get('/admin/notifications', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
    expect($json['unread'])->toBe(1)->and($json['entries'][0]['id'])->toBe($entry);
});

it('opens a page at its start for a cursor typed by hand, and JSON still refuses one', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);
    $entry = Ox::staffEntry($staffId);
    $browser = bellScreensStaff($staffId);

    $browser->get('/admin/notifications?after=nonsense')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('inbox.entries.0.id', $entry));

    expect($browser->get('/admin/notifications?after=nonsense', ['HTTP_ACCEPT' => 'application/json'])->getStatusCode())->toBe(422);
});

it('opens a page at its start for a well-formed cursor that finds nothing', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);
    $entry = Ox::staffEntry($staffId);

    // A ULID older than any entry: nothing comes after it.
    bellScreensStaff($staffId)->get('/admin/notifications?after=00000000000000000000000000')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('inbox.entries', 1)->where('inbox.entries.0.id', $entry));
});

it('carries the shop bell for a signed-in customer, in this store only, and lists the page in the account\'s menu', function () {
    $sara = Fx::customer();
    Ox::customerEntry($sara, 'sa');
    Ox::customerEntry($sara, 'ae');
    Ox::customerEntry(Fx::customer('omar@example.test'), 'sa');

    bellScreensCustomer()->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('frame.ops.unread', 1)
        ->where('frame.ops.words.view_all', 'View All')
        ->where('accountMenu.pages', fn (Collection $pages): bool => $pages->contains(fn (array $each): bool => $each['key'] === 'ops.notifications'
            && $each['label'] === 'Notifications' && $each['routeName'] === 'storefront.ops.bell')));
});

it('carries nothing for a guest, and sends a guest away from the full page', function () {
    $guest = new AdminBrowser('10.9.12.10');

    $guest->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('frame.ops.unread', null)->where('frame.ops.words', null));
    $guest->get('/sa/en/account/notifications')->assertRedirect();
});

it('carries no number during a staff view', function () {
    $browser = bellScreensStaff(Fx::staffWith([Platform::SETTINGS_VIEW], ['sa']));
    $browser->post('/admin/staff-view', ['store' => 'sa'])->assertRedirect('/sa/en');

    $browser->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('frame.ops.unread', null)
        ->where('frame.ops.words', null)
        ->whereType('staffView.name', 'string'));
});

it('asks for the shop bell on no partial reload', function () {
    Ox::customerEntry(Fx::customer(), 'sa');

    $props = bellScreensPartial(bellScreensCustomer(), '/sa/en/account/notifications', 'Ops/Storefront/Notifications');

    expect($props)->toHaveKey('inbox')
        ->and(array_key_exists('frame', $props))->toBeFalse();
});

it('opens the shop\'s full page on a plain visit, marks nothing, and keeps the JSON beside it', function () {
    $sara = Fx::customer();
    $entry = Ox::customerEntry($sara, 'sa');
    $browser = bellScreensCustomer();

    $browser->get('/sa/en/account/notifications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Storefront/Notifications')
        ->where('inbox.unread', 1)
        ->where('inbox.entries.0.id', $entry)
        ->where('inbox.entries.0.read', false));

    $json = $browser->get('/sa/en/account/notifications', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
    expect($json['unread'])->toBe(1);
});

it('pages the full page by the cursor it gave', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);

    foreach (range(1, 21) as $ignored) {
        Ox::staffEntry($staffId);
    }

    $browser = bellScreensStaff($staffId);
    $first = $browser->get('/admin/notifications', ['HTTP_ACCEPT' => 'application/json'])->json();

    expect($first['entries'])->toHaveCount(20)->and($first['next'])->toBeString();

    $browser->get('/admin/notifications?after='.$first['next'])->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('inbox.entries', 1)->where('inbox.next', null)->where('inbox.unread', 21));
});
