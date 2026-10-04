<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\ValueObject\RoleLevel;
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
| The frames on shadcn's blocks, in a real browser (frontend.md §1.11): the theme that follows the
| device, and the store switcher in the sidebar's header with an off store in it (access.md
| amendment 58(a)). The page tests beside these prove what each page is handed; these prove what a
| person sees and can press.
*/

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so it cannot see a transaction wrapped round the test.
beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    // The data outlives each test here, so Egypt starts on whatever an earlier run left it as -
    // and is put back on after, for the files that follow.
    panelFrameEgyptOn();
});

afterEach(function () {
    panelFrameEgyptOn();
});

function panelFrameEgyptOn(): void
{
    Fx::asSystem(fn () => app(ActivateStoreHandler::class)->handle(new ActivateStore('eg')));
}

function panelFrameSignIn(string $staffId): mixed
{
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    return visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        // The code is recorded only once the server has answered the password (raced, 2026-09-24).
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin');
}

/** Egypt switched off, its id read first: an off store's code finds no store. */
function panelFrameEgyptOff(): string
{
    $egypt = Fx::storeId('eg');
    Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

    return $egypt;
}

it('follows a dark device on a first visit', function () {
    // Followed by the page's head before the first paint, which AdminPagesTest proves in the markup;
    // this proves the page ends up dark, whichever of the two did it.
    // Nobody has chosen yet, so the choice is System: the server renders light and the page's head
    // turns it dark for a dark device (owner, 2026-10-02).
    $page = visit('/sa/en')->inDarkMode();

    expect($page->script('document.documentElement.dataset.mode'))->toBe('dark');
    $page->assertNoJavaScriptErrors();
});

/**
 * Waits, inside the page, for the server's answer to a theme choice: the switcher shows the choice
 * as pressed only once the page it answered with says so. Choosing keeps the page as it is while the
 * choice is saved, so moving on before the answer arrives would cancel it (lesson 121: the plugin's
 * assertions read once; poll inside the page instead). Not the cookie: Laravel encrypts it.
 */
function panelFrameThemeSaved(mixed $page, string $choice): bool
{
    return browserUntil($page, "document.querySelector('[data-test=\"theme-{$choice}\"]')?.dataset.state === 'on'");
}

it('stays light on a dark device once Light is chosen, and follows it again on System', function () {
    $page = visit('/sa/en')->inDarkMode();

    // The shop's one theme switch, in its footer.
    $page->click('[data-test="theme-light"]');
    expect(panelFrameThemeSaved($page, 'light'))->toBeTrue();
    $page->navigate('/sa/en');

    expect($page->script('document.documentElement.dataset.mode'))->toBe('light');

    $page->click('[data-test="theme-system"]');
    expect(panelFrameThemeSaved($page, 'system'))->toBeTrue();
    $page->navigate('/sa/en');

    expect($page->script('document.documentElement.dataset.mode'))->toBe('dark');
});

it('shows a staff member who covers an off store that store disabled, with the reason', function () {
    $staffId = Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa', 'eg']);
    $egypt = panelFrameEgyptOff();

    $page = panelFrameSignIn($staffId);
    $page->click('[data-test="store-switcher"]')
        ->assertSee('Egypt is switched off.');

    expect($page->script("document.querySelector('[data-test=\"store-{$egypt}\"]').getAttribute('aria-disabled')"))->toBe('true');
});

it('lets a Super Admin choose an off store, to prepare it before it opens', function () {
    $egypt = panelFrameEgyptOff();

    $superAdmin = Fx::staff(superAdmin: true);
    $page = panelFrameSignIn($superAdmin);
    $page->click('[data-test="store-switcher"]')
        ->click("[data-test=\"store-{$egypt}\"]");

    // The header now names the store being worked in.
    $page->assertSeeIn('[data-test="store-switcher"]', 'Egypt');
    // And says it is off, where they work, not only in the list (the review, 2026-10-03).
    $page->assertSeeIn('[data-test="store-switcher"]', 'Off');
    expect(DB::table('access.staff_users')->where('id', $superAdmin)->value('current_store_id'))->toBe($egypt);
});

it('folds the menu into business areas, opens the one being read, lets several stay open and remembers them', function () {
    // Staff and roles, stores and address forms: two areas of two screens each, so both fold.
    $staffId = Fx::staffWith([AccessPermissions::STAFF_VIEW, AccessPermissions::ROLE_MANAGE, PlatformPermissions::STORE_VIEW, AccessPermissions::ADDRESS_FORMAT_UPDATE], ['sa'], RoleLevel::Admin);
    $page = panelFrameSignIn($staffId);
    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/roles');

    // The area holding the screen opens by itself, the screen lit in it; the other is folded, its
    // screens not drawn at all (frontend.md §1.11, the owner's fix list, 2026-10-04).
    expect(browserUntil($page, "document.querySelector('[data-test=\"area-staff_and_permissions\"]')?.getAttribute('aria-expanded') === 'true'"))->toBeTrue();
    $page->assertAttribute('[data-test="area-store_settings"]', 'aria-expanded', 'false')
        ->assertMissing('a[href="/admin/stores"]')
        ->assertAttribute('a[href="/admin/roles"]', 'data-active', 'true');

    // Several may be open at once: opening Store Settings leaves Staff and Permissions open.
    $page->click('[data-test="area-store_settings"]')
        ->assertVisible('a[href="/admin/stores"]')
        ->assertAttribute('[data-test="area-staff_and_permissions"]', 'aria-expanded', 'true');

    // Remembered by this browser: the next page draws both open from the start.
    $page->navigate('/admin/staff');
    expect(browserUntil($page, "document.querySelector('[data-test=\"area-store_settings\"]')?.getAttribute('aria-expanded') === 'true'"))->toBeTrue();

    // Folded around the screen being read, the area itself is lit, so the person still sees where
    // they are.
    $page->click('[data-test="area-staff_and_permissions"]')
        ->assertAttribute('[data-test="area-staff_and_permissions"]', 'aria-expanded', 'false')
        ->assertAttribute('[data-test="area-staff_and_permissions"]', 'data-active', 'true');

    // The sidebar's own bar is thin too: the width is set on every element, not inherited.
    expect($page->script("getComputedStyle(document.querySelector('[data-slot=\"sidebar-content\"]')).scrollbarWidth"))->toBe('thin');

    // A menu locks the page's scroll, and the page already keeps its bar's room: nothing moves.
    $page->click('[data-test="person-menu"]');
    expect(browserUntil($page, "document.body.hasAttribute('data-scroll-locked')"))->toBeTrue()
        ->and($page->script('getComputedStyle(document.body).marginRight'))->toBe('0px');
    $page->keys('[data-test="person-menu"]', 'Escape');

    // On the rail of icons an area's icon opens its screens as a menu, and a screen opens from it.
    $page->click('[data-sidebar="trigger"]')
        ->click('[data-test="area-store_settings"]')
        ->click('[role="menuitem"][href="/admin/stores"]');

    expect(browserUntil($page, "window.location.pathname === '/admin/stores'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('draws thin scrollbars that keep the page\'s room, dark ones in dark mode', function () {
    // The rules are the whole system's, so the sign-in page shows them as well as any.
    $light = visit('/admin/sign-in');

    expect($light->script('getComputedStyle(document.documentElement).scrollbarGutter'))->toBe('stable')
        ->and($light->script('getComputedStyle(document.documentElement).scrollbarWidth'))->toBe('thin')
        ->and($light->script('getComputedStyle(document.documentElement).colorScheme'))->toBe('light');

    // On a dark device the browser's own parts - the scrollbars among them - are drawn dark.
    $dark = visit('/admin/sign-in')->inDarkMode();

    expect(browserUntil($dark, "getComputedStyle(document.documentElement).colorScheme === 'dark'"))->toBeTrue();
});
