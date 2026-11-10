<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| O7, Subscribers in a real browser (ops.md §4.15): the columns' words, a customer's hidden email and its reason in a
| Popover (by keyboard, and by a tap on a phone), a guest removed with Keep and Remove, a customer with no menu, newest
| first, a row kept after a removal below Show More, Arabic and a phone.
|
| Deliberately no RefreshDatabase, as the other browser tests. The list is newest first (owner, 2026-11-10; §4.1), so each
| test starts with `ae`'s list empty: another run's rows would push this one's off the page.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    DB::table('ops.subscribers')->where('store_id', Fx::storeId('ae'))->delete();
});

/**
 * @param  list<string>  $permissions
 */
function subscribersBrowser(array $permissions, string $locale = 'en'): mixed
{
    $staffId = Fx::staffWith($permissions, ['ae'], RoleLevel::Admin);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);

    $page = visit('/admin/sign-in')
        ->type('#email', (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin/sign-in/code' && document.querySelector('input[autocomplete=\"one-time-code\"]') !== null"))->toBeTrue();

    $page->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin'"))->toBeTrue();

    return $page;
}

/**
 * @param  array<string, mixed>  $with
 */
function subscribersBrowserRow(string $email, array $with = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('ops.subscribers')->insert([
        'id' => $id, 'store_id' => Fx::storeId('ae'), 'email' => $email, 'locale' => 'en', 'kind' => 'GUEST',
        'customer_id' => null, 'token_hash' => null, 'created_at' => now(), 'confirmed_at' => now(), ...$with,
    ]);

    return $id;
}

it('shows each kind in words, hides a customer\'s email saying why, and removes only a guest', function () {
    $run = substr((string) hrtime(true), -10);
    $guest = subscribersBrowserRow("guest{$run}@example.test");
    $waiting = subscribersBrowserRow("waiting{$run}@example.test", ['confirmed_at' => null, 'token_hash' => hash('sha256', $run)]);
    $customerEmail = "customer{$run}@example.test";
    $customer = subscribersBrowserRow($customerEmail, ['kind' => 'CUSTOMER', 'customer_id' => Fx::customer($customerEmail, 'ae')]);
    $page = subscribersBrowser(['ops.newsletter.manage']);

    $page->navigate('/admin/newsletter/subscribers?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"subscriber-{$guest}\"]') !== null"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"subscriber-{$guest}\"] [data-test=\"state\"]').textContent"))->toBe('Confirmed')
        ->and((string) $page->script("document.querySelector('[data-test=\"subscriber-{$waiting}\"] [data-test=\"state\"]').textContent"))->toBe('Waiting for Confirmation')
        ->and((string) $page->script("document.querySelector('[data-test=\"subscriber-{$customer}\"] [data-test=\"kind\"]').textContent"))->toBe('Customer');

    // This reader may not view the store's customers: the email stays hidden, and the reason is there to read.
    expect((string) $page->script("document.querySelector('[data-test=\"subscriber-{$customer}\"] [data-test=\"email\"]').textContent"))->toBe('Hidden');
    // By keyboard, not only the mouse: the word is a button, Enter opens the reason and Escape closes it.
    $hidden = "[data-test=\"subscriber-{$customer}\"] [data-test=\"hidden-email\"]";
    expect(browserSoon($page, "document.querySelector('{$hidden}')?.tagName === 'BUTTON'"))->toBeTrue();
    $page->keys($hidden, 'Enter');
    expect(browserSoon($page, "document.querySelector('[data-test=\"hidden-why\"]')?.textContent === 'You may not view this store\\'s customers.'"))->toBeTrue();
    $page->keys('[data-test="hidden-why"]', 'Escape');
    expect(browserSoon($page, "document.querySelector('[data-test=\"hidden-why\"]') === null"))->toBeTrue();

    // A customer's own switch decides: no menu on their row.
    expect($page->script("document.querySelector('[data-test=\"more-{$customer}\"]') === null"))->toBeTrue();

    // Keep leaves the guest; Remove takes them off.
    $page->click("[data-test=\"more-{$guest}\"]")->click('[data-test="remove"]');
    expect(browserSoon($page, "document.body.textContent.replace(/[\\u2068\\u2069]/g, '').includes('Remove guest{$run}@example.test from the newsletter?')"))->toBeTrue();
    $page->click('[data-test="modal-cancel"]');
    expect(browserSoon($page, "document.querySelector('[role=\"alertdialog\"]') === null"))->toBeTrue()
        ->and(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeTrue();

    $page->click("[data-test=\"more-{$guest}\"]")->click('[data-test="remove"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-remove\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-remove"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"subscriber-{$guest}\"]') === null && document.body.textContent.includes('Removed from the newsletter.')"))->toBeTrue()
        ->and(DB::table('ops.subscribers')->where('id', $guest)->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('shows a customer\'s email to someone who may view the store\'s customers', function () {
    $run = substr((string) hrtime(true), -10);
    $email = "shown{$run}@example.test";
    $customer = subscribersBrowserRow($email, ['kind' => 'CUSTOMER', 'customer_id' => Fx::customer($email, 'ae')]);
    $page = subscribersBrowser(['ops.newsletter.manage', 'access.customer.view']);

    $page->navigate('/admin/newsletter/subscribers?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"subscriber-{$customer}\"] [data-test=\"email\"]')?.textContent === '{$email}'"))->toBeTrue();
});

it('keeps the rows shown with Show More when a guest below them is removed', function () {
    $run = substr((string) hrtime(true), -10);
    // A minute apart, the first written the oldest.
    $ids = array_map(static fn (int $i): string => subscribersBrowserRow("deep{$i}.{$run}@example.test", ['created_at' => now()->subMinutes(60 - $i)]), range(1, 55));
    $page = subscribersBrowser(['ops.newsletter.manage']);
    $rows = "document.querySelectorAll('[data-test^=\"subscriber-\"]')";

    $page->navigate('/admin/newsletter/subscribers?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "{$rows}.length === 50"))->toBeTrue()
        // Newest first: the last written at the top.
        ->and((string) $page->script("{$rows}[0].dataset.test"))->toBe("subscriber-{$ids[54]}");
    $page->click('[data-test="more"]');
    // The first written are on the second page, newest of them first.
    $deep = $ids[4];
    $next = $ids[3];
    $gone = $ids[2];
    expect(browserSoon($page, "document.querySelector('[data-test=\"more-{$deep}\"]') !== null"))->toBeTrue()
        ->and((int) $page->script("{$rows}.length"))->toBe(55);

    $page->script("(() => { document.querySelector('[data-test=\"more-{$deep}\"]').scrollIntoView({ block: 'center' }); })()");
    expect(browserSoon($page, 'window.scrollY > 0'))->toBeTrue();
    $place = (int) $page->script('Math.round(window.scrollY)');
    $page->click("[data-test=\"more-{$deep}\"]")->click('[data-test="remove"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-remove\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-remove"]');

    // The row goes, every other row stays, and the place stays, but for the one row the page is now shorter by.
    expect(browserSoon($page, "document.querySelector('[data-test=\"subscriber-{$deep}\"]') === null && document.querySelector('[data-test=\"subscriber-{$next}\"]') !== null && {$rows}.length === 54"))->toBeTrue("{$page->script("{$rows}.length")} rows of 54")
        ->and(browserSoon($page, "window.scrollY > {$place} - 60", 5))->toBeTrue("scrolled to {$page->script('Math.round(window.scrollY)')}, was {$place}");

    // A refusal at this depth keeps its words on the page once the rows are back: another's removal got there first.
    DB::table('ops.subscribers')->where('id', $gone)->delete();
    $page->script("(() => { document.querySelector('[data-test=\"more-{$gone}\"]').scrollIntoView({ block: 'center' }); })()");
    $page->click("[data-test=\"more-{$gone}\"]")->click('[data-test="remove"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-remove\"]') !== null"))->toBeTrue();
    $page->click('[data-test="confirm-remove"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"subscriber-{$gone}\"]') === null && document.querySelector('[data-test=\"subscriber-{$next}\"]') !== null"))->toBeTrue();
    usleep(500_000);
    expect((bool) $page->script("document.querySelector('main').textContent.includes('This subscriber isn')"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone', function () {
    $run = substr((string) hrtime(true), -10);
    $long = 'a-very-long-address-with-no-break-in-it-'.$run.'@example.test';
    $guest = subscribersBrowserRow($long);
    $customerEmail = "phone{$run}@example.test";
    $customer = subscribersBrowserRow($customerEmail, ['kind' => 'CUSTOMER', 'customer_id' => Fx::customer($customerEmail, 'ae')]);
    $page = subscribersBrowser(['ops.newsletter.manage'], 'ar');
    $page->resize(375, 812);

    $page->navigate('/admin/newsletter/subscribers?store=ae', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test^=\"subscriber-\"]') !== null"))->toBeTrue()
        ->and((bool) $page->script('/[٠-٩]/.test(document.body.textContent)'))->toBeFalse()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    // A tap opens the hidden email's reason, which a tooltip never did; it fits the phone.
    $page->click("[data-test=\"subscriber-{$customer}\"] [data-test=\"hidden-email\"]");
    expect(browserSoon($page, "document.querySelector('[data-test=\"hidden-why\"]')?.textContent === 'لا يمكنك عرض عملاء هذا المتجر.'"))->toBeTrue()
        ->and(browserSoon($page, "(() => { const box = document.querySelector('[data-test=\"hidden-why\"]').getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth; })()"))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->keys('[data-test="hidden-why"]', 'Escape');
    expect(browserSoon($page, "document.querySelector('[data-test=\"hidden-why\"]') === null"))->toBeTrue();

    // The question with a long address fits the phone too, wrapped inside its dialog.
    $page->script("(() => { document.querySelector('[data-test=\"more-{$guest}\"]').scrollIntoView({ block: 'center' }); })()");
    $page->click("[data-test=\"more-{$guest}\"]")->click('[data-test="remove"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-remove\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "(() => { const box = document.querySelector('[role=\"alertdialog\"] h2'); return box !== null && box.scrollWidth <= box.clientWidth; })()"))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
