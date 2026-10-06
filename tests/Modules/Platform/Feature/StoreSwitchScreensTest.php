<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\withCookie;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A session that survives a redirect: phpunit.xml forces "array", which keeps nothing.
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| The store on/off switch over HTTP (platform.md §1.6; owner, 2026-10-01): an off store is as if it
| were never there for the shop and for staff, and a Super Admin turns it back on from the stores
| screen.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

function storeSwitchScreensTurnOff(string $code): void
{
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore($code)));
}

/**
 * A browser signed in completely as this staff member.
 */
function storeSwitchScreensSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

describe('the shop', function () {
    it('answers an off store\'s pages exactly as an unknown store\'s', function (string $path, string $unknown) {
        storeSwitchScreensTurnOff('eg');

        get($path)->assertNotFound();
        get($unknown)->assertNotFound();
        // The same answer, word for word; only the correlation id is new on every request.
        $off = (array) getJson($path)->json();
        $never = (array) getJson($unknown)->json();
        unset($off['correlation_id'], $never['correlation_id']);
        expect($off)->toBe($never)->and($off)->not->toBe([]);
    })->with([
        'a page' => ['/eg/ar', '/xx/ar'],
        'the store without a language' => ['/eg', '/xx'],
    ]);

    it('takes a remembered off store for no store, and shows the country page without it', function () {
        storeSwitchScreensTurnOff('eg');

        withCookie('tw_store', 'eg')->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Storefront/ChooseStore')
                ->has('stores', 2)
                ->where('stores.0.code', 'sa')
                ->where('stores.1.code', 'ae')
            );
    });

    it('offers only the stores that are on in the shop\'s store switcher', function () {
        storeSwitchScreensTurnOff('ae');

        get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('shop.available', 2)
            ->where('shop.available.0.code', 'sa')
            ->where('shop.available.1.code', 'eg')
        );
    });

    it('opens the store again, as it was, once it is turned back on', function () {
        storeSwitchScreensTurnOff('eg');
        get('/eg/ar')->assertNotFound();

        Fx::asSystem(fn () => app(ActivateStoreHandler::class)->handle(new ActivateStore('eg')));

        get('/eg/ar')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('name', 'مصر'));
    });
});

describe('the panel', function () {
    it('shows a Super Admin the off store on the stores screen, with what the switch needs', function () {
        storeSwitchScreensTurnOff('ae');
        $browser = storeSwitchScreensSignIn(Fx::staff(superAdmin: true));

        $browser->get('/admin/stores')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Platform/Admin/Stores/Index')
            ->where('maySwitch', true)
            ->has('stores', 3)
            ->where('stores.0.code', 'sa')
            ->where('stores.0.isActive', true)
            ->where('stores.0.isBase', true)
            ->where('stores.0.switchable', false)
            ->where('stores.2.code', 'ae')
            ->where('stores.2.isActive', false)
            ->where('stores.2.isBase', false)
            ->where('stores.2.switchable', true)
            // A store a Super Admin prepares before it opens: View Store lists it, marked off
            // (access.md amendments 58(a), 64).
            ->has('viewStores', 3)
            ->where('viewStores.0.name', 'Saudi Arabia')
            ->where('viewStores.2.name', 'United Arab Emirates')
            ->where('viewStores.2.isActive', false)
        );
    });

    it('turns a store off and on from the stores screen, and refuses the base store with a message', function () {
        $browser = storeSwitchScreensSignIn(Fx::staff(superAdmin: true));

        $off = $browser->post('/admin/stores/eg/deactivate')->assertRedirect();
        expect(AdminBrowser::flashed($off, 'status'))->toBe('Store turned off')
            ->and(DB::table('platform.stores')->where('code', 'eg')->value('is_active'))->toBeFalse();

        $on = $browser->post('/admin/stores/eg/activate')->assertRedirect();
        expect(AdminBrowser::flashed($on, 'status'))->toBe('Store turned on')
            ->and(DB::table('platform.stores')->where('code', 'eg')->value('is_active'))->toBeTrue();

        $base = $browser->post('/admin/stores/sa/deactivate')->assertRedirect();
        expect(AdminBrowser::formError($base))->toBe('Couldn\'t turn off the store "sa". The base store is always on.')
            ->and(DB::table('platform.stores')->where('code', 'sa')->value('is_active'))->toBeTrue();
    });

    it('hides the off store from an admin of every store on the stores screen and in View Store, and refuses them the switch', function () {
        storeSwitchScreensTurnOff('ae');
        $browser = storeSwitchScreensSignIn(Fx::staffWith(
            [PlatformPermissions::STORE_VIEW, PlatformPermissions::STORE_UPDATE],
            ['*'],
            RoleLevel::Admin,
        ));

        $browser->get('/admin/stores')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('maySwitch', false)
            ->has('stores', 2)
            ->where('stores.1.switchable', false)
            // Only a Super Admin is offered an off store (access.md amendments 58(a), 64).
            ->has('viewStores', 2)
            ->where('viewStores.1.code', 'eg')
        );

        expect(AdminBrowser::formError($browser->post('/admin/stores/ae/activate')->assertRedirect()))->not->toBeNull()
            ->and(AdminBrowser::formError($browser->post('/admin/stores/eg/deactivate')->assertRedirect()))->not->toBeNull()
            ->and(DB::table('platform.stores')->where('code', 'ae')->value('is_active'))->toBeFalse()
            ->and(DB::table('platform.stores')->where('code', 'eg')->value('is_active'))->toBeTrue();
    });
});
