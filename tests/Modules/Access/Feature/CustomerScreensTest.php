<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Customers, seen by staff (stage 2b step 4, frontend.md §3.7, G1 and G2).
|
| The handlers behind the four actions have their own tests in Access; these are about the screens:
| who appears in the list, what the filters do, and that a button is offered only to somebody who
| may press it - and only when it is the thing that can happen next.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A browser signed in as a staff member holding these permissions in these stores.
 *
 * Signed in properly - the password, then the code - rather than by binding an actor: these are
 * screens, and a screen is reached through the panel's own door. Named for this file: a function
 * declared in a Pest file is global to the whole suite.
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function customerScreensStaff(array $permissions, array $stores = ['sa']): AdminBrowser
{
    return customerScreensSignIn(Fx::staffWith($permissions, $stores, RoleLevel::Admin));
}

function customerScreensSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.7.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

function customerScreensSuperAdmin(): AdminBrowser
{
    return customerScreensSignIn(Fx::staff(superAdmin: true));
}

describe('the list (G1)', function () {
    it('shows the customers of the reader\'s own stores, and not the others', function () {
        $mine = Fx::customer('mine@example.test', 'sa');
        Fx::customer('theirs@example.test', 'eg');

        $browser = customerScreensStaff([AccessPermissions::CUSTOMER_VIEW], ['sa']);

        $browser->get('/admin/customers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Access/Admin/Customers/Index')
                ->has('customers', 1)
                ->where('customers.0.id', $mine)
                ->where('customers.0.email', 'mine@example.test')
                ->where('customers.0.accountType', 'INDIVIDUAL')
                ->where('customers.0.status', 'ACTIVE')
                ->where('customers.0.homeStore', 'Saudi Arabia')
                ->where('customers.0.emailVerified', false)
                ->where('total', 1)
            );
    });

    it('shows a Super Admin everyone', function () {
        Fx::customer('mine@example.test', 'sa');
        Fx::customer('theirs@example.test', 'eg');

        $browser = customerScreensSuperAdmin();

        $browser->get('/admin/customers')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('customers', 2)->where('total', 2));
    });

    it('narrows by kind, by status and by a search', function () {
        Fx::customer('person@example.test', 'sa');
        $company = Fx::customer('company@example.test', 'sa', 'company');

        $browser = customerScreensSuperAdmin();

        $browser->get('/admin/customers?type=COMPANY')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers', 1)
                ->where('customers.0.id', $company)
                ->where('accountType', 'COMPANY')
            );

        $browser->get('/admin/customers?search=person')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('customers', 1)
                ->where('customers.0.email', 'person@example.test')
            );

        $browser->get('/admin/customers?status=BLOCKED')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('customers', 0));
    });

    it('answers a kind nobody has with the refusal, not with everybody', function () {
        Fx::customer('person@example.test', 'sa');
        $browser = customerScreensSuperAdmin();

        // A value the caller sent, so it is answered rather than thrown at - and never quietly
        // ignored, which would show a list that does not match what the filter says.
        $browser->get('/admin/customers?type=neither')->assertStatus(422);
    });

    it('refuses somebody who may see no customer anywhere', function () {
        $browser = customerScreensStaff([AccessPermissions::STAFF_VIEW], ['sa']);

        $browser->get('/admin/customers')->assertForbidden();
    });
});

describe('one customer (G2)', function () {
    it('shows them, their addresses, and what may be done next', function () {
        $customerId = Fx::customer('sara@example.test', 'sa');
        $browser = customerScreensStaff([
            AccessPermissions::CUSTOMER_VIEW,
            AccessPermissions::CUSTOMER_BLOCK,
            AccessPermissions::CUSTOMER_DELETE,
        ], ['sa']);

        $browser->get("/admin/customers/{$customerId}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Access/Admin/Customers/Show')
                ->where('customer.email', 'sara@example.test')
                ->where('locale', 'en')
                ->where('addresses', [])
                // Active and not closing, so those are the two that can happen next.
                ->where('mayBlock', true)
                ->where('mayUnblock', false)
                ->where('mayStartDeletion', true)
                ->where('mayCancelDeletion', false)
                ->where('deletionDays', 14)
            );
    });

    it('offers nothing to somebody who may only look', function () {
        $customerId = Fx::customer('sara@example.test', 'sa');
        $browser = customerScreensStaff([AccessPermissions::CUSTOMER_VIEW], ['sa']);

        $browser->get("/admin/customers/{$customerId}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mayBlock', false)
                ->where('mayUnblock', false)
                ->where('mayStartDeletion', false)
                ->where('mayCancelDeletion', false)
            );
    });

    it('blocks an account with a reason, and then offers only the way back', function () {
        $customerId = Fx::customer('sara@example.test', 'sa');
        $browser = customerScreensStaff([AccessPermissions::CUSTOMER_VIEW, AccessPermissions::CUSTOMER_BLOCK], ['sa']);

        $browser->post("/admin/customers/{$customerId}/block", ['reason' => 'Chargebacks on three orders.'])
            ->assertRedirect("/admin/customers/{$customerId}");

        expect(DB::table('access.customers')->where('id', $customerId)->value('status'))->toBe('BLOCKED');

        $browser->get("/admin/customers/{$customerId}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('customer.status', 'BLOCKED')
                ->where('mayBlock', false)
                ->where('mayUnblock', true)
            );
    });

    it('will not act without a reason', function () {
        $customerId = Fx::customer('sara@example.test', 'sa');
        $browser = customerScreensStaff([AccessPermissions::CUSTOMER_VIEW, AccessPermissions::CUSTOMER_BLOCK], ['sa']);

        $refused = $browser->post("/admin/customers/{$customerId}/block", ['reason' => '']);

        // Read out of the response's own session: this browser keeps its cookies, so the test
        // case's session is not the one the request used.
        $errors = AdminBrowser::flashed($refused, 'errors');
        $errors = is_array($errors) ? ($errors['default']['messages'] ?? []) : [];

        expect($errors)->toHaveKey('reason')
            ->and(DB::table('access.customers')->where('id', $customerId)->value('status'))->toBe('ACTIVE');
    });

    it('starts the customer\'s own closing at their request, and can stop it again', function () {
        $customerId = Fx::customer('sara@example.test', 'sa');
        $browser = customerScreensStaff([AccessPermissions::CUSTOMER_VIEW, AccessPermissions::CUSTOMER_DELETE], ['sa']);

        $browser->post("/admin/customers/{$customerId}/delete", ['reason' => 'Asked us on the phone.'])
            ->assertRedirect("/admin/customers/{$customerId}");

        expect(DB::table('access.customers')->where('id', $customerId)->value('deletion_scheduled_for'))->not->toBeNull();

        $browser->get("/admin/customers/{$customerId}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mayStartDeletion', false)
                // For a customer who cannot sign in to stop it themselves, which is the only
                // other way (§3.7).
                ->where('mayCancelDeletion', true)
            );

        $browser->post("/admin/customers/{$customerId}/delete/cancel", ['reason' => 'Changed their mind.'])
            ->assertRedirect("/admin/customers/{$customerId}");

        expect(DB::table('access.customers')->where('id', $customerId)->value('deletion_scheduled_for'))->toBeNull();
    });

    it('refuses an action from somebody who may look but not act', function () {
        $customerId = Fx::customer('sara@example.test', 'sa');
        $browser = customerScreensStaff([AccessPermissions::CUSTOMER_VIEW], ['sa']);

        // Offering is never allowing: the button is not drawn, and the handler refuses anyway.
        $refused = $browser->post("/admin/customers/{$customerId}/block", ['reason' => 'Trying it on.']);

        expect(DB::table('access.customers')->where('id', $customerId)->value('status'))->toBe('ACTIVE')
            ->and($refused->getStatusCode())->toBeIn([302, 403]);
    });

    it('will not show a customer of another store at all', function () {
        $theirs = Fx::customer('theirs@example.test', 'eg');
        $browser = customerScreensStaff([AccessPermissions::CUSTOMER_VIEW], ['sa']);

        $browser->get("/admin/customers/{$theirs}")->assertNotFound();
    });
});
