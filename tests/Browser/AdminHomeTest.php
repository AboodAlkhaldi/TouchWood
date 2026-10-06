<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\B2B\Support\B2BFixtures;

use function Pest\Laravel\seed;

/*
| The admin home in a real browser (frontend.md §2.2; the owner's fix list, point 6): Home first in
| the menu, the cards, the store switcher (access.md amendment 64), and a waiting company opening its
| page.
|
| No RefreshDatabase - the suite keeps its data - so other tests' waiting companies may come first;
| what is checked is that the list leads to a company, not which one.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory(B2BFixtures::uploads());
});

it('opens a Super Admin\'s home on All Stores with the cards, switches to one store, and opens a waiting company', function () {
    B2BFixtures::sent(B2BFixtures::verifiedCompanyAccount());
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    // Home first in the menu, lit; the cards; All Stores chosen in the switcher.
    $page->assertPresent('[data-test="menu-home"][data-active="true"]')
        ->assertSeeIn('[data-test="card-b2b.approvals"]', 'Company Approvals')
        ->assertSeeIn('[data-test="card-b2b.approvals"]', 'Waiting the Longest')
        ->assertSeeIn('[data-test="card-platform.system"]', 'Stores On')
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelector('[data-test=\"store-filter\"]').value"))->toBe('')
        ->and($page->script("document.querySelector('[data-test=\"store-filter\"] option[value=\"\"]').textContent"))->toBe('All Stores');

    // One store (the owner, 2026-10-06): the address says which, and stores on and off - an
    // all-stores figure - goes.
    $page->select('[data-test="store-filter"]', 'sa');
    expect(browserUntil($page, "new URLSearchParams(location.search).get('store') === 'sa'"))->toBeTrue();
    $page->assertDontSeeIn('[data-test="card-platform.system"]', 'Stores On')
        ->assertNoJavaScriptErrors();

    // A waiting company opens its own page.
    $page->click('[data-test="card-b2b.approvals"] [data-test="home-row"]');
    expect(browserUntil($page, '/^\\/admin\\/companies\\/[0-9a-z]{26}$/.test(location.pathname)'))->toBeTrue();
});
