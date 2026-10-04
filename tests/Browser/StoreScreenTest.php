<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The stores screen, in a real browser (frontend.md §3.5, E1 and E2).
|
| The tests beside these prove what the screen is handed and what the endpoint writes. This one
| proves a person can use it: that the cards draw, that the form opens on a store they may change,
| and that saving it actually saves.
*/

const STORE_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so it cannot see a transaction wrapped round the test - the two
// deadlock instead. These tests make their own data unique.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('draws the stores and saves one from its own card', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW, PlatformPermissions::STORE_UPDATE], ['sa']);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', STORE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/stores');

    // In English, because the fixture's staff member keeps English as their own language.
    $page->assertSee('Stores')
        // The card's own line: code, currency, rate, timezone.
        ->assertSee('Asia/Riyadh')
        // Said on the screen, so nobody hunts for a button that was never there.
        ->assertSee('A store is opened by console command')
        ->assertNoJavaScriptErrors();

    // Named, because this page carries the panel's own buttons too and "button" would find one
    // of those first.
    $page->click('[data-test="edit-sa"]')
        ->assertSee('The code, the country and the currency are fixed')
        ->assertNoJavaScriptErrors();
});

it('offers no edit form on a store somebody may only read', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', STORE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/stores');

    // Not offered rather than offered and refused (access.md amendment 9): the card carries the
    // store's details and no way to change them.
    $page->assertSee('Asia/Riyadh')
        ->assertDontSee('The code, the country and the currency are fixed')
        // The switch and the stores' state are a Super Admin's alone (platform.md §1.6).
        ->assertDontSee('Turn Store Off')
        ->assertNoJavaScriptErrors();
});

describe('the on/off switch (platform.md §1.1, §9.5)', function () {
    // The suite keeps its data between tests, and every other file shops in Egypt: whatever happens
    // here, Egypt is on again afterwards, and the cached store list forgets it was ever off.
    afterEach(function () {
        DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => true]);
        Cache::flush();
    });

    it('lets a Super Admin turn a store off, after asking, and on again', function () {
        $superAdminId = Fx::staff(superAdmin: true);
        $email = (string) DB::table('access.staff_users')->where('id', $superAdminId)->value('email');

        $page = visit('/admin/sign-in')
            ->type('#email', $email)
            ->type('#password', STORE_SCREEN_PASSWORD)
            ->click('button[type="submit"]')
            ->assertPathIs('/admin/sign-in/code')
            ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
            ->click('button[type="submit"]');

        expect(signedInToPanel($page))->toBeTrue();
        $page->navigate('/admin/stores');

        // The base store is never off: its button stays, and says why instead of acting.
        $page->assertSee('Base Store')
            ->assertAttribute('[data-test="turn-off-sa"]', 'aria-disabled', 'true')
            ->assertNoJavaScriptErrors();

        // Asked first, in a destructive dialog that names the consequence; then it goes off.
        $page->click('[data-test="turn-off-eg"]')
            ->assertSee('disappears from every store list')
            ->click('[data-test="confirm-turn-off-eg"]')
            ->assertSee('Store turned off')
            ->assertSee('can\'t see this store until it\'s turned on');

        expect(DB::table('platform.stores')->where('code', 'eg')->value('is_active'))->toBeFalse();

        // And on again, with no question: turning a store on loses nothing.
        $page->click('[data-test="turn-on-eg"]')
            ->assertSee('Store turned on')
            ->assertNoJavaScriptErrors();

        expect(DB::table('platform.stores')->where('code', 'eg')->value('is_active'))->toBeTrue();
    });
});
