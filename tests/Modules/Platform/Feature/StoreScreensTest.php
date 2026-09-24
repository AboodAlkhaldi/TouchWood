<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A session that survives a redirect: phpunit.xml forces "array", which keeps nothing between
    // requests, and the form here is answered with one.
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| Stage 2b, step 3 - the stores screen over HTTP (frontend.md §3.5, E1 and E2).
|
| Every helper here is named after this file's subject. A function declared in a Pest file is global
| to the whole suite, so two files sharing a name stop every run (project conventions).
|
| These also prove the plumbing this step added: Platform's pages sit in the admin area that
| App\Http\AdminArea names and Access implements, without Platform reaching into Access to do it.
*/

/**
 * A browser signed in completely as a staff member holding these permissions in the Saudi store.
 *
 * @param  list<string>  $permissions
 */
function storeScreenSignIn(array $permissions): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, ['sa']);
    $browser = new AdminBrowser('10.8.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

describe('the stores screen', function () {
    it('opens in the admin panel, showing only the stores this person may see', function () {
        $browser = storeScreenSignIn([PlatformPermissions::STORE_VIEW]);

        $browser->get('/admin/stores')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->component('Platform/Admin/Stores/Index')
                ->has('stores', 1)
                ->where('stores.0.code', 'sa')
                // Shown, and not theirs to change: they hold the view permission and no other.
                ->where('stores.0.editable', false)
                ->where('stores.0.taxRatePercent', '15')
                ->has('timezones')
            );
    });

    it('refuses the screen to somebody who may see no store', function () {
        $browser = storeScreenSignIn([PlatformPermissions::MEDIA_UPLOAD]);

        // Navigation, not an action: this is the one case that answers with the error page rather
        // than a message (owner, 2026-09-24).
        $browser->get('/admin/stores')->assertForbidden();
    });

    it('saves a store, keeping the rate as basis points', function () {
        $browser = storeScreenSignIn([PlatformPermissions::STORE_VIEW, PlatformPermissions::STORE_UPDATE]);

        $browser->post('/admin/stores/sa', [
            'name_ar' => 'السعودية',
            'name_en' => 'Saudi Arabia',
            'tax_rate' => '15.5',
            'timezone' => 'Asia/Riyadh',
            'position' => '1',
        ])->assertRedirect();

        // The percentage a person typed, kept the way Platform keeps it (platform.md §1.1).
        expect(DB::table('platform.stores')->where('code', 'sa')->value('tax_rate_basis_points'))
            ->toBe(1550);
    });

    it('refuses a save from somebody who may read the store but not change it', function () {
        $browser = storeScreenSignIn([PlatformPermissions::STORE_VIEW]);
        $before = DB::table('platform.stores')->where('code', 'sa')->value('tax_rate_basis_points');

        $response = $browser->post('/admin/stores/sa', [
            'name_ar' => 'اسم آخر',
            'name_en' => 'Another name',
            'tax_rate' => '5',
            'timezone' => 'Asia/Riyadh',
            'position' => '1',
        ]);

        // An action on a screen they are already reading, so it is answered in the page - and
        // nothing changed, which is the half that matters.
        $response->assertRedirect();
        expect(DB::table('platform.stores')->where('code', 'sa')->value('tax_rate_basis_points'))
            ->toBe($before);
    });
});
