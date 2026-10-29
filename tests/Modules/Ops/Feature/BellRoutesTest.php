<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\OpsFixtures as Ox;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

/*
| The two bells over HTTP (ops.md §1.2, §4): JSON for the bell's menu - the page, the number, a mark,
| all marked - for a signed-in customer in the shop and a signed-in staff member in the panel. Nobody
| else has a bell.
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

function bellRoutesCustomer(string $email = 'sara@example.test'): AdminBrowser
{
    $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return $browser;
}

function bellRoutesStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

it('serves a customer their own bell in the shop: the page, the number, one read, all read', function () {
    $sara = Fx::customer();
    $first = Ox::customerEntry($sara, 'sa');
    Ox::customerEntry($sara, 'sa');
    $omars = Ox::customerEntry(Fx::customer('omar@example.test'), 'sa');
    $browser = bellRoutesCustomer();

    $page = $browser->get('/sa/en/account/notifications', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
    expect($page['unread'])->toBe(2)
        ->and($page['next'])->toBeNull()
        ->and(array_column($page['entries'], 'storeCode'))->toBe(['sa', 'sa'])
        ->and($page['entries'][1])->toMatchArray(['id' => $first, 'kind' => 'order_approved', 'title' => 'Order SA-0001 approved', 'read' => false, 'link' => '/sa/en/account/orders/SA-0001']);

    $browser->get('/sa/en/account/notifications/unread', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->assertExactJson(['unread' => 2]);
    $browser->post("/sa/en/account/notifications/{$first}/read")->assertOk()->assertExactJson(['unread' => 1]);
    $browser->post("/sa/en/account/notifications/{$omars}/read")->assertNotFound();
    $browser->post('/sa/en/account/notifications/read-all')->assertOk()->assertExactJson(['unread' => 0]);

    expect(DB::table('ops.inbox_entries')->whereNull('read_at')->pluck('id')->all())->toBe([$omars]);
});

it('serves a staff member their own bell in the panel', function () {
    $staff = Fx::staffWith(['ops.report.view'], ['sa']);
    $entry = Ox::staffEntry($staff, 'sa');
    $browser = bellRoutesStaff($staff);

    $page = $browser->get('/admin/notifications', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json();
    expect(array_column($page['entries'], 'id'))->toBe([$entry])
        ->and($page['entries'][0]['storeName'])->toBe('Saudi Arabia')
        ->and($page['unread'])->toBe(1);

    $browser->post("/admin/notifications/{$entry}/read")->assertOk()->assertExactJson(['unread' => 0]);
});

it('gives no bell to a visitor who is not signed in', function () {
    // The shop's signed-in pages send anyone else to the store's home, JSON or not (Access's RequireCustomer).
    getJson('/sa/en/account/notifications')->assertRedirect('/sa/en');
    getJson('/admin/notifications')->assertUnauthorized();
});
