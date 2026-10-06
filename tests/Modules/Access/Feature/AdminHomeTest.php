<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\B2B\Application\B2BPermissions;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| The admin home's cards (frontend.md §2.2; platform.md §2.6, §9.8; b2b.md amendment 27; the owner's
| fix list, point 6; access.md amendment 64): what each reader is handed, for which store or All
| Stores, and which stores the switcher offers.
*/

function adminHomeSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.11.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

it('opens a Super Admin\'s home on All Stores, with every store in the switcher, both cards and their words', function () {
    B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
    $browser = adminHomeSignIn(Fx::staff(superAdmin: true));

    $browser->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('Admin/Home')
        ->where('storeCode', null)
        ->where('offersAllStores', true)
        ->where('stores', [
            ['code' => 'sa', 'name' => 'Saudi Arabia', 'isActive' => true],
            ['code' => 'eg', 'name' => 'Egypt', 'isActive' => true],
            ['code' => 'ae', 'name' => 'United Arab Emirates', 'isActive' => true],
        ])
        ->where('storeTimezone', null)
        ->where('cards.0.key', 'b2b.approvals')
        ->where('cards.0.title', 'Company Approvals')
        ->where('cards.0.figures.0.label', 'Under Review')
        ->where('cards.0.figures.0.value', 1)
        ->where('cards.0.openLabel', 'View Companies')
        ->has('cards.0.rows', 1)
        ->where('cards.1.key', 'platform.system')
        ->where('cards.1.figures.0.label', 'Stores On')
        ->where('cards.1.figures.0.value', 3)
    );

    // One store, chosen in the switcher: Platform's card without stores on and off, and the store's
    // own time zone for the page's times.
    $browser->get('/admin?store=eg')->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('storeCode', 'eg')
        ->where('storeTimezone', 'Africa/Cairo')
        ->where('cards.1.key', 'platform.system')
        ->where('cards.1.figures.0.label', 'Failed Jobs')
    );
});

it('shows an admin of one store that store\'s cards, with no switch, though a store-free action reads as every store', function () {
    B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount(), 'eg');
    // Failed jobs are store-free: held at all, they read as every store, and must not offer All
    // Stores - which would hide this store's companies until This Store was pressed (the review of P5).
    $browser = adminHomeSignIn(Fx::staffWith([B2BPermissions::COMPANY_VIEW, PlatformPermissions::JOBS_MANAGE], ['sa'], RoleLevel::Admin));

    $browser->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('storeCode', 'sa')
        ->where('offersAllStores', false)
        // One store and no All Stores: nothing to switch, so the page draws no switcher.
        ->has('stores', 1)
        ->has('cards', 2)
        ->where('cards.0.key', 'b2b.approvals')
        // Egypt's waiting company is not theirs to see.
        ->where('cards.0.figures.0.value', 0)
        ->has('cards.0.rows', 0)
        ->where('cards.1.key', 'platform.system')
        ->where('cards.1.figures.0.label', 'Failed Jobs')
    );

    // Asking for another store's figures, or a store that does not exist, is refused.
    $browser->get('/admin?store=eg')->assertForbidden();
    $browser->get('/admin?store=zz')->assertForbidden();
});

it('gives a reader of two stores of three no All Stores, and a switcher of their two', function () {
    B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount(), 'ae');
    $browser = adminHomeSignIn(Fx::staffWith([B2BPermissions::COMPANY_VIEW], ['sa', 'eg']));

    $browser->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('storeCode', 'sa')
        ->where('offersAllStores', false)
        ->where('stores.0.code', 'sa')
        ->where('stores.1.code', 'eg')
        ->has('stores', 2)
        ->where('cards.0.key', 'b2b.approvals')
        ->where('cards.0.figures.0.value', 0)
    );
});

it('shows no card to staff holding none of the cards\' permissions', function () {
    $browser = adminHomeSignIn(Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']));

    $browser->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('offersAllStores', false)
        ->where('cards', [])
    );
});
