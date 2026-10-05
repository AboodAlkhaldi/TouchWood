<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Infrastructure\Http\LaravelStaffViews;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/*
| The staff view (access.md §1.11, amendment 60; frontend.md §2.2, §2.3; platform.md §2.6, §9.9): a
| staff member opens the shop from the panel as themselves - a visitor's shop plus the off stores they
| cover, no customer, no ordering - through a pass in its own cookie that never outlives its admin
| session.
*/

/** A browser signed in to the panel as this staff member, reading it in English. */
function staffViewSignIn(string $staffId, ?AdminBrowser $browser = null): AdminBrowser
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => 'en']);
    $browser ??= new AdminBrowser('10.12.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

function staffViewRows(string $staffId): int
{
    return DB::table('access.staff_views')->where('staff_user_id', $staffId)->count();
}

function staffViewOff(string $code): void
{
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore($code)));
}

describe('opening it', function () {
    it('opens the shop of the store being worked in as the staff member, with a pass only its hash is kept of, audited', function () {
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $browser = staffViewSignIn($staffId);

        $response = $browser->post('/admin/staff-view')->assertRedirect('/sa/en');

        $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === LaravelStaffViews::COOKIE);
        $token = (string) $browser->cookie(LaravelStaffViews::COOKIE);
        $row = DB::table('access.staff_views')->where('staff_user_id', $staffId)->first();

        // Path / so the shop reads it, never the panel's /admin; HTTP-only; gone with the browser.
        expect($cookie?->getPath())->toBe('/')
            ->and($cookie?->isHttpOnly())->toBeTrue()
            ->and($cookie?->getSameSite())->toBe('lax')
            ->and($cookie?->getExpiresTime())->toBe(0)
            ->and($token)->not->toBe('')
            ->and($row?->token_hash)->toBe(hash('sha256', $token))
            ->and($row?->store_id)->toBe(Fx::storeId('sa'))
            ->and(Fx::audits('access.staff_user.staff_view_opened', $staffId))->toBe(1);

        $browser->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('staffView.name', trim(DB::table('access.staff_users')->where('id', $staffId)->value('first_name').' '.DB::table('access.staff_users')->where('id', $staffId)->value('last_name')))
            ->where('shopper', null)
        );
    });

    it('replaces the pass this admin session opened before, keeping one', function () {
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $browser = staffViewSignIn($staffId);

        $browser->post('/admin/staff-view');
        $first = $browser->cookie(LaravelStaffViews::COOKIE);
        $browser->post('/admin/staff-view');

        expect(staffViewRows($staffId))->toBe(1)
            ->and($browser->cookie(LaravelStaffViews::COOKIE))->not->toBe($first);
    });

    it('opens nothing for a staff member with no store to work in, and nothing for a visitor', function () {
        // Their only store, switched off once they hold it (access.md amendment 53).
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['eg']);
        staffViewOff('eg');
        $browser = staffViewSignIn($staffId);

        $browser->post('/admin/staff-view')->assertRedirect();
        (new AdminBrowser)->post('/admin/staff-view')->assertRedirect('/admin/sign-in');

        expect(DB::table('access.staff_views')->count())->toBe(0);
    });
});

describe('what the shop shows', function () {
    it('hides a customer signed in to the shop while the view holds, and shows them again once it is left', function () {
        $customerId = Fx::customer('sara@example.test', 'sa');
        $browser = new AdminBrowser('10.12.1.'.random_int(20, 250));
        $browser->post('/sa/en/account/sign-in', ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        staffViewSignIn($staffId, $browser);

        $browser->post('/admin/staff-view')->assertRedirect('/sa/en');

        // A visitor's shop: no customer, so nothing customer-only opens.
        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('shopper', null)->has('staffView'));
        $browser->get('/sa/en/account')->assertRedirect('/sa/en');

        // Nor can anything act as the customer meanwhile: their own posts go home, untouched.
        $browser->post('/sa/en/account/sign-out')->assertRedirect('/sa/en');
        $browser->post('/sa/en/account/profile', ['first_name' => 'Changed'])->assertRedirect('/sa/en');

        $left = $browser->post('/staff-view/leave', ['store' => 'sa'])->assertRedirect('/sa/en');
        $forgotten = collect($left->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === LaravelStaffViews::COOKIE);

        // The customer's session was never touched; the pass's cookie is cleared where it was set.
        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('shopper.id', $customerId)->where('staffView', null));
        expect(staffViewRows($staffId))->toBe(0)
            ->and($forgotten?->isCleared())->toBeTrue()
            ->and($forgotten?->getPath())->toBe('/')
            ->and($browser->cookie(LaravelStaffViews::COOKIE))->toBeNull()
            ->and(DB::table('access.customers')->where('id', $customerId)->value('first_name'))->toBe('Sara');
    });

    it('lets an off store be looked at, never written into', function () {
        $staffId = Fx::staff(superAdmin: true);
        $egypt = Fx::storeId('eg');
        staffViewOff('eg');
        $browser = staffViewSignIn($staffId);
        $browser->post('/admin/current-store', ['store' => $egypt]);
        $browser->post('/admin/staff-view')->assertRedirect('/eg/en');

        $browser->get('/eg/en/register')->assertOk();
        $browser->post('/eg/en/account/register', [
            'email' => 'noura@example.test',
            'password' => Fx::CUSTOMER_PASSWORD,
            'first_name' => 'Noura',
            'last_name' => 'Saleh',
            'account_type' => 'individual',
            'locale' => 'en',
            'terms' => '1',
        ])->assertNotFound();

        expect(DB::table('access.customers')->where('email', 'noura@example.test')->exists())->toBeFalse();

        // Leaving from it goes to the country page: a visitor cannot open it.
        $browser->post('/staff-view/leave', ['store' => 'eg'])->assertRedirect('/');
    });

    it('opens an off store only for a Super Admin\'s staff view, and lists it marked off', function () {
        // Read while it is on: an off store's code answers as an unknown one.
        $egypt = Fx::storeId('eg');
        $coveringId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa', 'eg']);
        staffViewOff('eg');

        // A visitor: as if it were never there.
        (new AdminBrowser)->get('/eg/en')->assertNotFound();

        // A Super Admin working in it (the owner, 2026-10-06: only a Super Admin views an off store).
        $superAdmin = staffViewSignIn(Fx::staff(superAdmin: true));
        $superAdmin->post('/admin/current-store', ['store' => $egypt]);
        $superAdmin->post('/admin/staff-view')->assertRedirect('/eg/en');
        $superAdmin->get('/eg/en')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('shop.code', 'eg')
            ->where('shop.available', fn (Collection $stores): bool => $stores->contains(fn (array $store): bool => $store['code'] === 'eg' && $store['isActive'] === false))
        );

        // Any other staff member - even one whose stores include it: a 404, and not in the list.
        foreach ([$coveringId, Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa'])] as $staffId) {
            $other = staffViewSignIn($staffId);
            $other->post('/admin/staff-view')->assertRedirect('/sa/en');
            $other->get('/eg/en')->assertNotFound();
            $other->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page
                ->where('shop.available', fn (Collection $stores): bool => ! $stores->contains(fn (array $store): bool => $store['code'] === 'eg'))
            );
        }
    });

    it('does nothing for a forged pass', function () {
        staffViewOff('eg');
        $browser = new AdminBrowser;
        $browser->setCookie(LaravelStaffViews::COOKIE, 'not-a-pass');

        $browser->get('/eg/en')->assertNotFound();
        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null));
    });
});

describe('how long it lives', function () {
    it('ends with the admin session it came from', function () {
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $browser = staffViewSignIn($staffId);
        $browser->post('/admin/staff-view');

        $browser->post('/admin/sign-out');

        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null));
        expect(staffViewRows($staffId))->toBe(0);
    });

    it('ends when that admin session is ended from another device, or found idle by the panel', function () {
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $here = staffViewSignIn($staffId);
        $there = staffViewSignIn($staffId);
        $here->post('/admin/staff-view');

        // From the account's sessions list on another device: the row goes, and the pass with it.
        $there->post('/admin/account/sessions/'.$here->cookie((string) config('access.admin.session_cookie')));
        $here->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null));

        // A busy shop keeps a pass while the panel sits idle - until the panel finds itself idle.
        $there->post('/admin/staff-view');
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(20));
        $there->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->has('staffView.name'));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(20));
        $there->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->has('staffView.name'));
        $there->get('/admin')->assertRedirect('/admin/sign-in');
        $there->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null));

        expect(staffViewRows($staffId))->toBe(0);
    });

    it('ends every pass of theirs on sign out everywhere, another browser\'s included', function () {
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $here = staffViewSignIn($staffId);
        $there = staffViewSignIn($staffId);
        $here->post('/admin/staff-view');
        $there->post('/admin/staff-view');

        $here->post('/admin/account/sessions/all');

        expect(staffViewRows($staffId))->toBe(0);
        $there->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null));
    });

    it('ends at the next shop page once they are disabled or their session version changes', function (array $change) {
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $browser = staffViewSignIn($staffId);
        $browser->post('/admin/staff-view');

        DB::table('access.staff_users')->where('id', $staffId)->update($change);
        app(GrantsReader::class)->refresh($staffId);

        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null));
        expect(staffViewRows($staffId))->toBe(0);
    })->with([
        'disabled' => [['status' => 'DISABLED']],
        'a new session version' => [['session_version' => 99]],
    ]);

    it('ends after the staff idle limit without a shop page, and kept seen while used', function () {
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $browser = staffViewSignIn($staffId);
        $browser->post('/admin/staff-view');

        // Twenty minutes, twice: each page keeps it.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(20));
        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->has('staffView.name'));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(20));
        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->has('staffView.name'));

        // Thirty-one minutes idle.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(31));
        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null));
        expect(staffViewRows($staffId))->toBe(0);
    });

    it('ends twelve hours after that admin sign-in, not after it was opened, however busy the shop', function () {
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $browser = staffViewSignIn($staffId);

        // Eleven hours in the panel, never idle, and only then View Store.
        foreach (range(1, 26) as $step) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(25));
            $browser->get('/admin')->assertOk();
        }
        $browser->post('/admin/staff-view')->assertRedirect('/sa/en');

        // Busy in the shop: 10:50 after the sign-in, 11:15, 11:40, then 12:05 - the pass is an hour
        // old, but its admin sign-in is past the maximum.
        foreach (range(1, 2) as $step) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(25));
            $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->has('staffView.name'));
        }
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(25));
        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null));

        expect(staffViewRows($staffId))->toBe(0);
    });

    it('ends when that browser signs in as a customer, or registers', function (Closure $become) {
        Fx::customer('sara@example.test', 'sa');
        $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_VIEW], ['sa']);
        $browser = staffViewSignIn($staffId);
        $browser->post('/admin/staff-view');

        $become($browser)->assertRedirect('/sa/en');

        $browser->get('/sa/en')->assertInertia(fn (AssertableInertia $page) => $page->where('staffView', null)->has('shopper.id'));
        expect(staffViewRows($staffId))->toBe(0);
    })->with([
        // The closure itself: a parameter typed Closure is handed the dataset's value as it is (lesson 133).
        'signing in' => [fn (AdminBrowser $browser) => $browser->post('/sa/en/account/sign-in', ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD])],
        'registering' => [fn (AdminBrowser $browser) => $browser->post('/sa/en/account/register', [
            'email' => 'noura@example.test',
            'password' => Fx::CUSTOMER_PASSWORD,
            'first_name' => 'Noura',
            'last_name' => 'Saleh',
            'account_type' => 'individual',
            'locale' => 'en',
            'terms' => '1',
        ])],
    ]);

    it('takes a customer leaving a view that had already ended back home, refusing nothing', function () {
        Fx::customer('sara@example.test', 'sa');
        $browser = new AdminBrowser;
        $browser->post('/sa/en/account/sign-in', ['email' => 'sara@example.test', 'password' => Fx::CUSTOMER_PASSWORD]);

        $browser->post('/staff-view/leave', ['store' => 'sa'])->assertRedirect('/sa/en');
        // A code naming nothing that is on leads to the country page, never elsewhere.
        $browser->post('/staff-view/leave', ['store' => '//evil.example'])->assertRedirect('/');
    });
});
