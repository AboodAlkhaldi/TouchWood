<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The panel's header search (flags-2026-11.md S7, F13) in a real browser: Ctrl K opens it, typing narrows the
| menu's pages, Enter opens the one lit, and a page the person may not open is not in the list. The list is the
| menu the page already has, so what is missing here is missing from the sidebar too.
*/

// Deliberately no RefreshDatabase: the suite serves the application in this process (see PanelFrameTest).
beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function adminSearchSignIn(string $staffId): mixed
{
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    return visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin');
}

it('opens on Ctrl K, filters the pages by name and opens the one chosen with Enter', function () {
    $page = adminSearchSignIn(Fx::staffWith([PlatformPermissions::STORE_VIEW, AccessPermissions::STAFF_VIEW], ['sa']));
    expect(signedInToPanel($page))->toBeTrue();

    $page->assertVisible('[data-test="admin-search"]');
    // The key is pressed on the page's own button: the listener is on the document, so it hears it there.
    $page->keys('[data-test="admin-search"]', 'Control+k');

    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-input\"]') !== null"))->toBeTrue();
    $page->assertVisible('[data-test="admin-search-platform-stores"]')
        ->assertVisible('[data-test="admin-search-access-staff"]');

    $page->type('[data-test="admin-search-input"]', 'Stores');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-access-staff\"]') === null"))->toBeTrue();
    $page->assertVisible('[data-test="admin-search-platform-stores"]')
        ->keys('[data-test="admin-search-input"]', 'Enter');

    expect(browserUntil($page, "window.location.pathname === '/admin/stores'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('does not list a page the person may not open, and says so when nothing matches', function () {
    $page = adminSearchSignIn(Fx::staffWith([PlatformPermissions::STORE_VIEW], ['sa']));
    expect(signedInToPanel($page))->toBeTrue();

    $page->click('[data-test="admin-search"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-platform-stores\"]') !== null"))->toBeTrue();

    // Staff and Roles are not in this person's menu, so they are not in the search.
    $page->assertMissing('[data-test="admin-search-access-staff"]')
        ->assertMissing('[data-test="admin-search-access-roles"]');

    $page->type('[data-test="admin-search-input"]', 'zzzz-no-such-page');
    expect(browserUntil($page, "document.body.innerText.includes('No pages found.')"))->toBeTrue();
});

it('reaches both pages called Customers by keyboard, gives focus back, and works in Arabic and on a phone', function () {
    $page = adminSearchSignIn(Fx::staffWith([PlatformPermissions::STORE_VIEW, AccessPermissions::STAFF_VIEW, AccessPermissions::CUSTOMER_VIEW, LoyaltyPermissions::VIEW], ['sa']));
    expect(signedInToPanel($page))->toBeTrue();

    // Access's and Loyalty's pages share the name "Customers": the arrow moves from the one lit to the other, and
    // Enter opens the one lit, not always the first.
    $lit = "document.querySelector('[data-test=\"admin-search-command\"] [aria-selected=\"true\"]')?.dataset.test ?? ''";
    $page->click('[data-test="admin-search"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-input\"]') !== null"))->toBeTrue()
        // The panel's dialogs draw no Close button of their own (frontend.md §1.11, edit 5).
        ->and($page->script("document.querySelector('[data-slot=\"dialog-close\"]') === null"))->toBeTrue();
    $page->type('[data-test="admin-search-input"]', 'Customers');
    expect(browserUntil($page, "document.querySelectorAll('[data-test=\"admin-search-command\"] [cmdk-item]').length === 2"))->toBeTrue();
    $first = (string) $page->script($lit);
    $page->keys('[data-test="admin-search-input"]', 'ArrowDown');
    $second = (string) $page->script($lit);
    expect([$first, $second])->toEqualCanonicalizing(['admin-search-access-customers', 'admin-search-loyalty-customers']);
    $page->keys('[data-test="admin-search-input"]', 'Enter');
    $path = $second === 'admin-search-loyalty-customers' ? '/admin/points/customers' : '/admin/customers';
    expect(browserUntil($page, "window.location.pathname === '{$path}'"))->toBeTrue();

    // Closed with Escape, focus goes back to the search button that opened it (frontend.md §6).
    $page->click('[data-test="admin-search"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-input\"]') !== null"))->toBeTrue();
    $page->keys('[data-test="admin-search-input"]', 'Escape');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-input\"]') === null && document.activeElement?.dataset.test === 'admin-search'"))->toBeTrue();

    // A section's name finds its pages (owner, 2026-11-20): the Points section's name lists Loyalty's Customers page,
    // not Access's, which sits in another section.
    $page->click('[data-test="admin-search"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-loyalty-customers\"]') !== null"))->toBeTrue();
    $section = (string) $page->script("document.querySelector('[data-test=\"admin-search-loyalty-customers\"]').closest('[cmdk-group]').querySelector('[cmdk-group-heading]').textContent.trim()");
    expect($section)->not->toBe('');
    $page->type('[data-test="admin-search-input"]', $section);
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-loyalty-customers\"]') !== null && document.querySelector('[data-test=\"admin-search-access-customers\"]') === null"))->toBeTrue("typing '{$section}' does not list its pages");
    $page->keys('[data-test="admin-search-input"]', 'Escape');

    // In Arabic, right to left, an Arabic name narrows the list.
    $page->click('[data-test="language"]');
    expect(browserSoon($page, "document.documentElement.getAttribute('dir') === 'rtl'"))->toBeTrue();
    $page->click('[data-test="admin-search"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-input\"]') !== null"))->toBeTrue();
    $page->type('[data-test="admin-search-input"]', 'المتاجر');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-access-staff\"]') === null && document.querySelector('[data-test=\"admin-search-platform-stores\"]') !== null"))->toBeTrue();
    $page->keys('[data-test="admin-search-input"]', 'Escape');

    // A phone (signed in at a computer's width first, lessons 118): the button is its icon alone, and the dialog
    // fits the screen with nothing sideways.
    $page->resize(375, 812);
    $page->navigate('/admin');
    expect(browserSoon($page, "document.querySelector('[data-test=\"admin-search\"]')?.getBoundingClientRect().width <= 40"))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('[data-test="admin-search"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"admin-search-input\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "(() => { const box = document.querySelector('[data-slot=\"dialog-content\"]').getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth; })()"))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
