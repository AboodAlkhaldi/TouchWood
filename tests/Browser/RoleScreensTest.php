<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The roles screens, in a real browser (frontend.md §3.4).
|
| The integration tests beside these prove what the screens are handed. These prove a person can
| use it: that the list draws at all, that the editor groups actions the way staff think, and that
| ticking one and saving actually creates the role.
*/

const ROLE_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase. The suite serves the application in this process, but a served
// request opens its own connection, so it cannot see a transaction wrapped round the test - and the
// two deadlock against each other instead (found by running it, 2026-09-23). These tests therefore
// leave the database as they found it by making their own data unique, not by rolling it back.

beforeEach(function () {
    // The browser suite serves the application inside this process, so it inherits the suite's
    // session driver - and phpunit.xml forces "array", which keeps nothing between requests.
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * A new Super Admin's email address, for signing in through the real screens.
 *
 * The sign-in itself is written out in each test rather than returned from here: the page object
 * the browser plugin hands back is not the type its own signature promises, so a helper that
 * returned it would have to lie about what it returns.
 */
function newSuperAdminEmail(): string
{
    return (string) DB::table('access.staff_users')
        ->where('id', Fx::staff(superAdmin: true))
        ->value('email');
}

it('draws the roles screen, and offers it from the menu', function () {
    $name = 'Shopkeeper '.Str::random(6);
    Fx::role([AccessPermissions::STAFF_VIEW], nameEn: $name);

    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/roles');

    // In English, because the fixture's staff member keeps English as their own language and the
    // panel is read in the reader's language, not the system's.
    $page->assertSee($name)
        ->assertSee('Roles')
        // The first real menu entry: the sidebar had nothing in it until roles shipped.
        ->assertSee('Staff and Permissions')
        // The comparison table the design asked to keep: areas down the side, roles across.
        ->assertSee('Permissions by Role')
        ->assertNoJavaScriptErrors();
});

it('groups the actions by business area in the editor, rather than by declaration order', function () {
    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/roles/new');

    // The areas staff think in (stage 2b, P2), not the order the modules happened to load.
    $page->assertSee('Staff and Permissions')
        ->assertSee('Store Settings and Tax')
        ->assertNoJavaScriptErrors();
});

it('creates a role from the screen, ticking an action and saving it', function () {
    $english = 'Warehouse keeper '.Str::random(6);
    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        // The click only dispatches the submit; the code is not recorded until the server has
        // answered it. Waiting for the code screen first is what makes reading it reliable
        // (this raced, and lost, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/roles/new');

    $page->type('#name_ar', 'أمين المستودع '.Str::random(4))
        ->type('#name_en', $english)
        // One action, named precisely because there are a dozen boxes on this screen and a loose
        // selector matches all of them: each box carries its action's name (the shadcn rebuild).
        ->click('[data-test="permission-'.AccessPermissions::STAFF_VIEW.'"]')
        ->click('button[type="submit"]')
        ->assertSee($english);

    expect(DB::table('access.roles')->where('name->en', $english)->exists())->toBeTrue();
});

it('shows an admin no way into a role that is not theirs to change', function () {
    // An admin sees admin roles and cannot open them. The screen offers no edit button at all,
    // rather than offering one that fails when pressed (access.md amendment 9).
    $name = 'Manager '.Str::random(6);
    Fx::role([AccessPermissions::STAFF_ASSIGN_ROLE], RoleLevel::Admin, nameEn: $name);

    $staffId = Fx::staffWith(
        [AccessPermissions::ROLE_MANAGE, PlatformPermissions::STORE_VIEW],
        ['sa'],
        RoleLevel::Admin,
    );
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]');

    // The click above only dispatches the submit; the code is not recorded until the server has
    // answered it. Waiting for the code screen first is what makes reading it reliable (this
    // raced, and lost, 2026-09-24).
    $page->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/roles')
        ->assertSee($name)
        ->assertNoJavaScriptErrors();
});

it('lets somebody narrow the permissions table by area, and hide the roles they are not comparing', function () {
    // Two roles, so there is something to hide and something to keep.
    $kept = 'Keeper '.Str::random(6);
    $hidden = 'Hidden '.Str::random(6);
    Fx::role([AccessPermissions::STAFF_VIEW], nameEn: $kept);
    Fx::role([PlatformPermissions::STORE_UPDATE], nameEn: $hidden);

    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/roles');

    $page->assertSee('Permissions by Role')->assertNoJavaScriptErrors();

    // Counted in the table itself rather than asserted as page text: every business area is also
    // a menu group, so each of these words is in the sidebar whatever the table is showing.
    $actions = (int) $page->script('document.querySelectorAll("table tbody tr").length');
    $columns = (int) $page->script('document.querySelectorAll("table thead th").length');
    $headers = (array) $page->script('[...document.querySelectorAll("table thead th")].slice(0, 2).map((th) => th.innerText.trim())');

    expect($actions)->toBeGreaterThan(1)
        // A plain table (owner, frontend.md §1.11 #5): the area is a column of its own, then the
        // action, then one column per role.
        ->and($headers)->toBe(['Area', 'Action'])
        ->and($columns)->toBeGreaterThanOrEqual(4);

    // The filter narrows the rows to the area somebody is actually looking at.
    $page->type('input[placeholder="Filter the areas"]', 'Media')->assertNoJavaScriptErrors();

    $narrowed = (int) $page->script('document.querySelectorAll("table tbody tr").length');
    // The area is named once, at the head of its rows, as a row-group header.
    $area = (string) $page->script('document.querySelector("table tbody th[scope=rowgroup]").innerText');

    expect($narrowed)->toBeLessThan($actions)
        ->and(strtolower($area))->toContain('media');

    // Nothing sticks any more (owner, §1.11 #5, replacing the sticky area bands of 2026-09-24).
    $sticky = (int) $page->script(
        '[...document.querySelectorAll("table th, table td, table th *, table td *")].filter((cell) => getComputedStyle(cell).position === "sticky").length',
    );

    expect($sticky)->toBe(0);

    // And the columns menu opens with a row per role, so ten roles can become two; its header is
    // one word (Geist's Menu).
    $page->click('[data-test="columns"]')
        ->assertSee($hidden)
        ->assertPresent('[role="menuitemcheckbox"]')
        ->assertNoJavaScriptErrors();

    // The menu's own header, not the sidebar's "Roles" (the review of batch A).
    expect((string) $page->script('document.querySelector("[role=menu] [data-slot=dropdown-menu-label]")?.innerText ?? ""'))->toBe('Roles');
});

/**
 * How many of one area's boxes are ticked, among those the author may change ("free") and those
 * locked to them, read from the state Radix writes on each box.
 *
 * @return array<string, mixed> on, off, free; lockedOn, locked
 */
function roleScreenArea(mixed $page, string $area): array
{
    return (array) $page->script(<<<JS
        (() => {
            const boxes = [...document.querySelectorAll('[data-test="area-{$area}"] [data-test^="permission-"]')];
            const locked = boxes.filter((box) => box.getAttribute('aria-disabled') === 'true');
            const free = boxes.filter((box) => box.getAttribute('aria-disabled') !== 'true');
            return {
                on: free.filter((box) => box.dataset.state === 'checked').length,
                off: free.filter((box) => box.dataset.state === 'unchecked').length,
                lockedOn: locked.filter((box) => box.dataset.state === 'checked').length,
                locked: locked.length,
                free: free.length,
            };
        })()
        JS);
}

it('ticks every action of an area with its Select All, shows a dash for some, and clears them again', function () {
    $page = visit('/admin/sign-in')
        ->type('#email', newSuperAdminEmail())
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/roles/new');
    $all = '[data-test="select-all-staff_and_permissions"]';

    // One per area (the owner, 2026-10-06), named for a screen reader with its area.
    $page->assertAttribute($all, 'aria-label', 'Select All in Staff and Permissions')
        ->assertAttribute($all, 'data-state', 'unchecked');

    // One action ticked: some, so a dash (frontend.md §1.11 edit 6) rather than a tick.
    $page->click('[data-test="permission-'.AccessPermissions::STAFF_VIEW.'"]')
        ->assertAttribute($all, 'data-state', 'indeterminate');
    expect($page->script("getComputedStyle(document.querySelector('{$all} [data-slot=\"checkbox-indicator\"] svg:last-child')).display"))->toBe('block')
        ->and($page->script("getComputedStyle(document.querySelector('{$all} [data-slot=\"checkbox-indicator\"] svg:first-child')).display"))->toBe('none');

    // From some, it ticks the rest.
    $page->click($all)->assertAttribute($all, 'data-state', 'checked');
    $ticked = roleScreenArea($page, 'staff_and_permissions');

    expect($ticked['on'])->toBe($ticked['free'])
        ->and($ticked['free'])->toBeGreaterThan(1)
        // Another area is left as it was.
        ->and(roleScreenArea($page, 'store_settings')['on'])->toBe(0);

    // From all, it clears them.
    $page->click($all)->assertAttribute($all, 'data-state', 'unchecked');

    expect(roleScreenArea($page, 'staff_and_permissions')['on'])->toBe(0);
    $page->assertNoJavaScriptErrors();
});

it('ticks only what an admin may give with Select All, leaving the locked actions as they are', function () {
    // An admin holding two of the area's actions: the rest are shown locked (access.md §1.5).
    $staffId = Fx::staffWith([AccessPermissions::ROLE_MANAGE, AccessPermissions::STAFF_VIEW, PlatformPermissions::STORE_VIEW], ['sa'], RoleLevel::Admin);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', ROLE_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/roles/new');

    $page->click('[data-test="select-all-staff_and_permissions"]');
    $area = roleScreenArea($page, 'staff_and_permissions');

    expect($area['free'])->toBeGreaterThan(0)
        ->and($area['on'])->toBe($area['free'])
        ->and($area['locked'])->toBeGreaterThan(0)
        ->and($area['lockedOn'])->toBe(0);
    $page->assertAttribute('[data-test="select-all-staff_and_permissions"]', 'data-state', 'checked')
        ->assertNoJavaScriptErrors();
});
