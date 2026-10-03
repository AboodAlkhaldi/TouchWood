<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| A page's own data never replaces the language the page is displayed in (owner, 2026-09-27).
|
| Every page carries `locale` and `direction`: the language this browser reads the system in. Five
| pages also carried a person's **communication** language - the one their emails go in - under the
| same name, and a page's own props are laid over the shared ones. On those pages the language
| switch read the email language instead, offered "English" on a page that was already English, and
| never got anywhere. The owner met it on Account & settings. The field is `communicationLocale`.
|
| Every test here makes the two languages **differ**. With them equal - which is what every fixture
| used to do - the clash is invisible, and it was.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A staff member whose emails go in $communicationLocale, signed in, reading the panel in $display.
 *
 * Named for this file: a function declared in a Pest file is global to the whole suite.
 *
 * @param  list<string>  $permissions
 * @return array{0: AdminBrowser, 1: string}
 */
function pageLanguageStaff(string $communicationLocale, string $display, array $permissions, RoleLevel $level = RoleLevel::Staff): array
{
    $staffId = Fx::staffWith($permissions, ['sa'], $level);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $communicationLocale]);

    $browser = new AdminBrowser('10.9.2.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])
        ->assertRedirect('/admin');

    // This browser's choice, which is what the panel is displayed in (frontend.md §2.2).
    $browser->post('/admin/preferences', ['preference' => 'locale', 'value' => $display]);

    return [$browser, $staffId];
}

/**
 * The shared language props, as they must be whatever the page's own data says.
 */
function pageLanguageIs(AssertableInertia $page, string $display): AssertableInertia
{
    return $page
        ->where('locale', $display)
        ->where('direction', $display === 'ar' ? 'rtl' : 'ltr');
}

it('keeps Account & settings in the language it is displayed in, and names the email language apart', function (string $display, string $communication) {
    [$browser] = pageLanguageStaff($communication, $display, [AccessPermissions::STAFF_VIEW]);

    $browser->get('/admin/account')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => pageLanguageIs($page, $display)
            ->where('communicationLocale', $communication)
            // The words follow the displayed language too: the page no longer contradicts itself.
            // The keys themselves contain dots, so the map is read whole rather than by a path.
            ->where('translations', fn (Collection $words): bool => $words->get('access::account.title') === ($display === 'ar' ? 'الحساب والإعدادات' : 'Account & Settings'))
        );
})->with([
    'read in English, written to in Arabic - the owner\'s own case' => ['en', 'ar'],
    'read in Arabic, written to in English' => ['ar', 'en'],
]);

it('keeps a colleague\'s page in the reader\'s language, not the colleague\'s', function () {
    [$browser] = pageLanguageStaff('ar', 'en', [AccessPermissions::STAFF_VIEW], RoleLevel::Admin);
    $colleague = Fx::staffWith([AccessPermissions::STAFF_VIEW], ['sa']);
    DB::table('access.staff_users')->where('id', $colleague)->update(['locale' => 'ar']);

    $browser->get("/admin/staff/{$colleague}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => pageLanguageIs($page, 'en')
            ->where('communicationLocale', 'ar')
        );
});

it('keeps a customer\'s page in the reader\'s language, not the customer\'s', function () {
    // The customer first: registering one acts as a guest, which a signed-in browser must not
    // follow (the order CustomerScreensTest uses).
    $customerId = Fx::customer();
    DB::table('access.customers')->where('id', $customerId)->update(['locale' => 'ar']);
    [$browser] = pageLanguageStaff('ar', 'en', [AccessPermissions::CUSTOMER_VIEW], RoleLevel::Admin);

    $browser->get("/admin/customers/{$customerId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => pageLanguageIs($page, 'en')
            ->where('communicationLocale', 'ar')
        );
});

it('keeps the invitation form in the reader\'s language, and starts the new person\'s language from it', function () {
    // What StaffScreensTest gives the admin who invites: the form offers roles and stores.
    [$browser] = pageLanguageStaff('ar', 'en', [
        AccessPermissions::STAFF_VIEW,
        AccessPermissions::STAFF_INVITE,
        AccessPermissions::STAFF_ASSIGN_ROLE,
        PlatformPermissions::STORE_UPDATE,
    ], RoleLevel::Admin);

    $browser->get('/admin/staff/invite')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => pageLanguageIs($page, 'en')
            ->where('communicationLocale', 'en')
        );
});

it('keeps the shop\'s account page in the language of its address, not the customer\'s email language', function (string $display, string $communication) {
    $customerId = Fx::customer();
    DB::table('access.customers')->where('id', $customerId)->update(['locale' => $communication]);

    $browser = new AdminBrowser;
    $browser->post("/sa/{$display}/account/sign-in", ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD])
        ->assertRedirect("/sa/{$display}");

    $browser->get("/sa/{$display}/account")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => pageLanguageIs($page, $display)
            ->where('communicationLocale', $communication)
        );
})->with([
    'read in English, written to in Arabic' => ['en', 'ar'],
    'read in Arabic, written to in English' => ['ar', 'en'],
]);
