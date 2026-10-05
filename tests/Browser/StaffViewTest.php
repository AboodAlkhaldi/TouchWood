<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The staff view in a real browser (access.md §1.11; frontend.md §2.2, §2.3): View Store in the
| panel's header opens the shop as the staff member, with the line and the menu that lead back, and
| a Super Admin sees the store they are preparing while it is off.
|
| No RefreshDatabase - the suite keeps its data - so Egypt is switched on again afterwards.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

afterEach(function () {
    Fx::asSystem(fn () => app(ActivateStoreHandler::class)->handle(new ActivateStore('eg')));
});

function staffViewBrowserSignIn(string $staffId): mixed
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'en']);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

it('opens the shop from the panel as the staff member, and goes back', function () {
    $page = staffViewBrowserSignIn(Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']));

    $page->click('[data-test="view-store"]');
    expect(browserUntil($page, "location.pathname === '/sa/en'"))->toBeTrue();

    $page->assertSeeIn('[data-test="staff-view-line"]', 'Staff view')
        ->assertPresent('[data-test="staff-view-menu"]')
        ->assertMissing('[data-test="shopper-menu"]')
        ->assertNoJavaScriptErrors();

    // The menu: the panel and the way out of the view, no account.
    $page->click('[data-test="staff-view-menu"]')
        ->assertPresent('[data-test="staff-view-panel"]')
        ->assertPresent('[data-test="staff-view-leave"]')
        ->assertMissing('[data-test="my-account"]');

    $page->click('[data-test="staff-view-leave"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"staff-view-line\"]') === null"))->toBeTrue();
    $page->assertPresent('a[href$="/sign-in"]');
});

it('takes a Super Admin into the off store they are preparing, and back to the panel', function () {
    // Read while it is on: an off store's code answers as an unknown one.
    $egypt = Fx::storeId('eg');
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
    $superAdmin = Fx::staff(superAdmin: true);
    DB::table('access.staff_users')->where('id', $superAdmin)->update(['current_store_id' => $egypt]);
    $page = staffViewBrowserSignIn($superAdmin);

    $page->click('[data-test="view-store"]');
    expect(browserUntil($page, "location.pathname === '/eg/en'"))->toBeTrue();
    $page->assertPresent('[data-test="staff-view-line"]')->assertNoJavaScriptErrors();
    expect($page->script("document.querySelector('[data-test=\"country-switch\"] option[value=\"eg\"]')?.textContent ?? ''"))->toContain('(Off)');

    $page->click('[data-test="staff-view-back"]');
    expect(browserUntil($page, "location.pathname === '/admin'"))->toBeTrue();
});
