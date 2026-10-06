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
| fix list, point 6): what each reader is handed, for which scope, and whether the switch is offered.
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

it('opens a Super Admin\'s home on All Stores, with the switch, both cards and their words', function () {
    B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
    $browser = adminHomeSignIn(Fx::staff(superAdmin: true));

    $browser->get('/admin')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('Admin/Home')
        ->where('scope', 'all')
        ->where('offersAllStores', true)
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

    // This Store, asked for: the store being worked in; Platform's card without stores on and off.
    $browser->get('/admin?scope=store')->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('scope', 'store')
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
        ->where('scope', 'store')
        ->where('offersAllStores', false)
        ->has('cards', 2)
        ->where('cards.0.key', 'b2b.approvals')
        // Egypt's waiting company is not theirs to see.
        ->where('cards.0.figures.0.value', 0)
        ->has('cards.0.rows', 0)
        ->where('cards.1.key', 'platform.system')
        ->where('cards.1.figures.0.label', 'Failed Jobs')
    );

    // Asking for All Stores changes nothing they may see.
    $browser->get('/admin?scope=all')->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('scope', 'store')
        ->where('cards.0.key', 'b2b.approvals')
        ->where('cards.0.figures.0.value', 0)
    );
});

it('gives a reader of two stores of three no All Stores', function () {
    B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount(), 'ae');
    $browser = adminHomeSignIn(Fx::staffWith([B2BPermissions::COMPANY_VIEW], ['sa', 'eg']));

    $browser->get('/admin?scope=all')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->where('scope', 'store')
        ->where('offersAllStores', false)
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
