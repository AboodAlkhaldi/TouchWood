<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Loyalty\Application\Command\AddPoints\AddPoints;
use Modules\Loyalty\Application\Command\AddPoints\AddPointsHandler;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| Loyalty's screens in a real browser (loyalty.md §4.1). The feature tests beside these prove what
| the pages are handed; these prove a person can use them: the holders list opens a customer, the
| change by hand really changes the balance shown, the customer page carries the Points section, and
| the shop's Points tab draws - at a phone's width too, with nothing scrolling sideways.
|
| Deliberately no RefreshDatabase, as the other browser tests: every customer here has an address and
| a name of its own, from the clock.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Lx::programme();
});

/**
 * A customer of the Saudi store with a name of their own and points given there.
 *
 * @return array{0: string, 1: string, 2: string} the id, the email and the name
 */
function loyaltyScreenCustomer(int $points): array
{
    // Its own name, short as a real one: the clock's last digits.
    $tag = substr((string) hrtime(true), -6);
    $email = strtolower((string) Str::ulid()).'@example.test';
    $customer = Fx::customer($email, 'sa');
    DB::table('access.customers')->where('id', $customer)->update(['first_name' => "Loyal{$tag}", 'last_name' => 'Browser']);
    // A change by hand is a staff member's; the real ActorContext is given back after it, as
    // AccessFixtures::customer() does, so the browser's requests read who is acting themselves.
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    try {
        app(AddPointsHandler::class)->handle(new AddPoints($customer, Fx::storeId('sa'), $points, 'Welcome'));
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }

    return [$customer, $email, "Loyal{$tag} Browser"];
}

/**
 * Nothing when the page fits the screen's width; otherwise the page's width and what sticks out, so a
 * failure names its element (a wide table scrolls inside its own box, never the page).
 */
function loyaltyScreenOverflow(mixed $page): string
{
    return (string) $page->script(<<<'JS'
        (() => {
            const width = document.documentElement.clientWidth;
            if (document.documentElement.scrollWidth <= width) { return ''; }
            const out = [];
            for (const el of document.querySelectorAll('body *')) {
                const box = el.getBoundingClientRect();
                if (box.right > width + 1 && box.width > 0 && out.length < 6) {
                    out.push(el.tagName.toLowerCase() + '.' + String(el.className).slice(0, 50) + ' right=' + Math.round(box.right));
                }
            }
            return 'width ' + width + ', page ' + document.documentElement.scrollWidth + ': ' + out.join(' | ');
        })()
        JS);
}

/** Signed in to the panel as a new Super Admin. */
function loyaltyScreenPanel(): mixed
{
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');

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

it('opens a holder from the list and adds points by hand, the balance and the history following', function () {
    [$customer, , $name] = loyaltyScreenCustomer(70_000);
    $page = loyaltyScreenPanel();

    $page->navigate('/admin/points/customers?store=sa', BROWSER_PAGE_LOAD)
        ->assertSee('Who holds points in this store')
        ->assertSee($name)
        ->click("[data-test=\"holder-{$customer}\"] a")
        ->assertPathIs("/admin/points/customers/{$customer}");

    $page->click('[data-test="change-points"]')
        ->assertSee('Change Points')
        ->type('#points', '25')
        ->type('#reason', 'A browser said thank you')
        ->click('[data-test="confirm-change"]');

    expect(browserUntil($page, "document.querySelector('[data-test=\"points\"]')?.textContent.includes('70,025')"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"history\"]')?.textContent.includes('A browser said thank you')"))->toBeTrue()
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(70_025);
});

it('keeps Remove out of reach past the balance, saying why', function () {
    [$customer] = loyaltyScreenCustomer(10);
    $page = loyaltyScreenPanel();

    $page->navigate("/admin/points/customers/{$customer}?store=sa", BROWSER_PAGE_LOAD)
        ->click('[data-test="change-points"]')
        ->click('[data-test="mode-remove"]')
        ->type('#points', '11')
        ->type('#reason', 'Too many');

    expect(browserUntil($page, "document.querySelector('[data-test=\"confirm-change\"]')?.getAttribute('aria-disabled') === 'true'"))->toBeTrue()
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(10);
});

it('shows the customer\'s points on their own page, and every Points page fits a phone', function () {
    [$customer] = loyaltyScreenCustomer(1_234);
    $page = loyaltyScreenPanel();

    $page->navigate("/admin/customers/{$customer}", BROWSER_PAGE_LOAD)
        ->assertPresent('[data-test="points-section"]')
        ->assertPresent('[data-test="points-sa"]');

    $page->resize(375, 812);

    foreach (['/admin/points/customers?store=sa', "/admin/points/customers/{$customer}?store=sa", '/admin/points/redemptions?store=sa', "/admin/customers/{$customer}"] as $uri) {
        $page->navigate($uri, BROWSER_PAGE_LOAD);

        expect(loyaltyScreenOverflow($page))->toBe('', "{$uri} scrolls sideways on a phone");
    }
});

it('draws the shop account\'s Points tab, with how it works, at a phone\'s width too', function () {
    [, $email] = loyaltyScreenCustomer(500);

    $page = visit('/sa/en/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]');

    expect(browserUntil($page, "window.location.pathname === '/sa/en'"))->toBeTrue();

    $page->navigate('/sa/en/account/points', BROWSER_PAGE_LOAD)
        ->assertSee('My Points')
        ->assertSee('Every 1.00 SAR you spend adds 1 to your points.')
        ->assertPresent('[data-test="history"]');

    $page->resize(375, 812);
    $page->navigate('/sa/en/account/points', BROWSER_PAGE_LOAD);

    expect(loyaltyScreenOverflow($page))->toBe('');
});
