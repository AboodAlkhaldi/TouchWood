<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pest\Browser\Api\PendingAwaitablePage;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The shop's sign-in screens in a real browser (stage 2b step 4, frontend.md §3.6, F3-F6).
|
| The feature tests beside these know the server sends the right props and the right refusals. What
| they cannot know is whether a person can get through: whether the form draws, whether pressing
| the button registers anybody, and whether the header afterwards says who they are and how to
| leave.
|
| No RefreshDatabase, and the cache is emptied first, for the reasons written out at the top of
| AccountScreenTest. Nothing here is rolled back, so every account this file makes has an address
| of its own - a fixed one would work once and fail on every run after it.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    Cache::flush();
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** An address nobody has used before, because this suite keeps what it writes. */
function shopAddress(): string
{
    return strtolower((string) Str::ulid()).'@example.test';
}

it('registers somebody through the form and says who they are afterwards', function () {
    $email = shopAddress();

    $page = visit('/sa/en/register');

    $page->assertSee('Create Account')
        ->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        // The terms are not accepted by default and the account cannot be made without them.
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]');

    // Registering signs them in at once and lands them in the store (owner, 2026-09-20).
    $page->assertPathIs('/sa/en')
        ->assertSee('Noura Saleh')
        // The address is not confirmed yet, and the header is where they learn it.
        ->assertSee('Confirm Your Email')
        ->assertNoJavaScriptErrors();
});

it('shows an unconfirmed customer where their link went, and offers another', function () {
    $email = shopAddress();

    $page = visit('/sa/en/register');
    $page->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en');

    $page->click('[data-test="verify-email"]')
        ->assertPathIs('/sa/en/verify-email')
        ->assertSee($email)
        ->click('[data-test="resend-verification"]')
        // Access's own words for a link on its way, never ones this screen invented.
        ->assertSee((string) __('access::auth.verification_sent', [], 'en'))
        ->assertNoJavaScriptErrors();
});

it('signs somebody out from the header, leaving the way back in', function () {
    $email = shopAddress();

    $page = visit('/sa/en/register');
    $page->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en')
        ->assertSee('Noura Saleh');

    // The name opens the shopper's menu, where Sign Out is last (owner's #4, 2026-10-02).
    $page->click('[data-test="shopper-menu"]')
        ->click('[data-test="sign-out"]')
        ->assertDontSee('Noura Saleh')
        ->assertSee('Sign In')
        ->assertNoJavaScriptErrors();
});

it('refuses a registration in the shop\'s own words, on the form', function () {
    // A refusal that appears only as a toast at the foot of the page, for six seconds, reads as
    // nothing having happened at all (step 1's own lesson).
    $email = shopAddress();

    $first = visit('/sa/en/register');
    $first->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en');

    // The same address again, from a browser that is not signed in.
    $second = visit('/sa/en/sign-in');
    $second->navigate('/sa/en/register')
        ->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Someone')
        ->type('#last_name', 'Else')
        ->type('#email', $email)
        ->type('#password', 'another long password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en/register')
        ->assertSee("Couldn't create the account: this email already has one. Sign in instead.")
        ->assertNoJavaScriptErrors();
});

it('asks for the kind of account on purpose, and says so when none was picked', function () {
    // Nothing is chosen when the page opens: the choice can never be changed, so it is made on
    // purpose (Geist's Radio rule; owner, 2026-10-04). Sending without it stays on the form and says
    // what is missing, in the page's own words.
    $page = visit('/sa/en/register');

    $page->assertNotChecked('[data-test="account-type-individual"]')
        ->assertNotChecked('[data-test="account-type-company"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', shopAddress())
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en/register')
        ->assertSee((string) __('access::auth.account_type_required', [], 'en'))
        ->click('[data-test="account-type-company"]')
        ->assertDontSee((string) __('access::auth.account_type_required', [], 'en'))
        ->assertNoJavaScriptErrors();
});

it('will not send a new password while the two boxes differ', function () {
    // The endpoint takes `password` alone, so a typo in the box nobody can read would have set a
    // password they did not mean and told them nothing (owner, 2026-09-24). The page is the only
    // place this is caught, so the page is where it is tested.
    //
    // The token is not read by the page - a spent link is refused when the form is sent, in the
    // same words for every reason - so any token draws it.
    //
    // The words are asked for in the page's own language, not the test process's: these tests
    // serve the application in this very process, so the locale a served request leaves behind is
    // whatever ran last.
    $page = visit('/sa/en/password/reset/a-token-this-page-never-reads');

    $page->type('#password', 'a long enough password')
        ->type('#password_repeat', 'a long enough passwerd')
        // Out of reach at once, and said once the box is left (Geist: validate on blur, not on
        // every keystroke - the shadcn rebuild).
        ->assertDisabled('[data-test="save-password"]')
        ->assertDontSee((string) __('access::auth.passwords_differ', [], 'en'))
        ->click('#password')
        ->assertSee((string) __('access::auth.passwords_differ', [], 'en'))
        ->assertDisabled('[data-test="save-password"]');

    // And it lets go the moment they agree.
    $page->clear('#password_repeat')
        ->type('#password_repeat', 'a long enough password')
        ->assertDontSee((string) __('access::auth.passwords_differ', [], 'en'))
        ->assertEnabled('[data-test="save-password"]')
        ->assertNoJavaScriptErrors();
});

/**
 * A browser with a new account in it, signed in - which is what registering leaves behind.
 *
 * @return array{0: string, 1: PendingAwaitablePage}
 */
function shopSignedIn(): array
{
    $email = shopAddress();

    $page = visit('/sa/en/register');
    $page->click('[data-test="account-type-individual"]')
        ->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en');

    return [$email, $page];
}

it('opens the account from the header and shows what is still missing', function () {
    [$email, $page] = shopSignedIn();

    $page->click('[data-test="shopper-menu"]')
        ->click('[data-test="my-account"]')
        ->assertPathIs('/sa/en/account')
        ->assertSee('My Account')
        // Their own address, which they cannot change, said where it is rather than refused later.
        ->assertSee($email)
        ->assertSee('Your email address cannot be changed.')
        // Nothing verified yet, so this is the page that says what ordering waits for.
        ->assertSeeIn('[data-test="before-ordering"]', 'Ordering')
        ->assertSeeIn('[data-test="before-ordering"]', 'Confirm your email address and phone number to place orders.')
        ->assertNoJavaScriptErrors();
});

it('fits the register and sign-in pages and every account tab on a phone', function () {
    // Phones are supported (frontend.md §2.1). The visitor's pages first; the account is made at a
    // desktop's size, the page's React taking the form over first (lesson 118).
    // Each page measured once React has drawn it: an empty page fits anything.
    $wide = 'document.documentElement.scrollWidth <= window.innerWidth';
    $visitor = visit('/sa/en/register');
    $visitor->resize(375, 812);
    expect(browserUntil($visitor, "document.querySelector('[data-test=\"account-type-individual\"]') !== null && {$wide}"))->toBeTrue('the register page scrolls sideways');
    $visitor->navigate('/sa/en/sign-in', BROWSER_PAGE_LOAD);
    expect(browserUntil($visitor, "document.querySelector('#email') !== null && {$wide}"))->toBeTrue('the sign-in page scrolls sideways');

    [, $page] = shopSignedIn();
    $page->resize(375, 812);

    foreach (['profile', 'security', 'phone', 'addresses', 'close'] as $tab) {
        $page->navigate("/sa/en/account?tab={$tab}", BROWSER_PAGE_LOAD);
        expect(browserUntil($page, "document.querySelector('[data-test=\"tab-{$tab}\"]')?.getAttribute('aria-selected') === 'true' && {$wide}"))->toBeTrue("the {$tab} tab scrolls sideways");
    }

    // The account's list is folded behind Account Menu (owner, 2026-10-29), so the tab's own content is
    // the first thing on the screen; unfolded, a tab chosen from it folds it again and shows its panel.
    $shown = "document.querySelector('[data-test=\"tab-phone\"]').checkVisibility()";
    $page->assertVisible('[data-test="account-menu"]')->assertMissing('[data-test="tab-phone"]');
    $page->click('[data-test="account-menu"]');
    expect(browserUntil($page, $shown))->toBeTrue('Account Menu did not unfold the list');

    // An arrow key only walks the tabs (Radix opens each as it is reached): the list stays unfolded, the
    // focus on the tab reached (the review of the phone audit).
    $page->keys('[data-test="tab-close"]', 'ArrowUp');
    expect(browserUntil($page, "document.activeElement?.dataset.test === 'tab-addresses' && {$shown}"))->toBeTrue('an arrow key folded the list');

    // Pressed, a tab folds it, and the focus goes to its panel rather than to the hidden tab.
    $page->click('[data-test="tab-phone"]');
    expect(browserUntil($page, "new URLSearchParams(location.search).get('tab') === 'phone' && !{$shown}"))->toBeTrue('the list stayed open after a tab was chosen')
        ->and(browserUntil($page, "document.activeElement?.getAttribute('role') === 'tabpanel'"))->toBeTrue('the focus did not go to the panel');
    $page->assertSee('Add Phone Number')->assertNoJavaScriptErrors();

    // From a tablet's width the list is always there, and the button is gone.
    $page->resize(1024, 800);
    expect(browserUntil($page, $shown))->toBeTrue('the list is folded at a tablet\'s width');
    $page->assertMissing('[data-test="account-menu"]');
});

it('saves a new name and comes back to the tab it was sent from', function () {
    [, $page] = shopSignedIn();

    $page->navigate('/sa/en/account')
        ->clear('#first_name')
        ->type('#first_name', 'Maryam')
        ->click('[data-test="save-profile"]')
        // Back on the details tab, not at the top of the first one - and the header, which is on
        // every page of the shop, is saying the new name.
        ->assertSee('Maryam Saleh')
        ->assertNoJavaScriptErrors();
});

it('adds a phone number in two steps', function () {
    [, $page] = shopSignedIn();

    // A number nobody has used before. Nothing here is rolled back, and a number another customer
    // already holds is refused before any code goes out - so a fixed one works once and fails on
    // every run after it (found by running the whole suite, 2026-09-25).
    $phone = '+9665'.random_int(10000000, 99999999);

    $page->navigate('/sa/en/account')
        ->click('[data-test="tab-phone"]')
        ->assertSee('No number yet.')
        ->type('#phone', $phone)
        ->click('[data-test="send-phone-code"]')
        // Waiting for the second step before reading the code: the click only dispatches the
        // submit, and the code is not recorded until the server has answered it. Reading it in the
        // same chain raced, and lost (2026-09-25) - the same lesson the panel's sign-in learned.
        ->assertSee('Verification Code');

    $page->type('#phone_code', RecordingSecurityMessages::installed()->lastCode())
        ->click('[data-test="confirm-phone"]')
        ->assertSee($phone)
        ->assertNoJavaScriptErrors();
});

it('adds an address in the country it belongs to, and marks it as the usual one', function () {
    [, $page] = shopSignedIn();

    $page->navigate('/sa/en/account?tab=addresses')
        ->assertSee('Saudi Arabia')
        ->assertSee('Add one to have orders delivered in this country.')
        ->click('[data-test="add-address-sa"]')
        ->type('#label-sa', 'Home')
        ->type('#recipient-sa', 'Noura Saleh')
        ->type('#phone-sa', '+966512345678')
        // The fields under those three are the store's own, drawn from its format rather than
        // written into the page: these are the ones Saudi Arabia asks for.
        ->type('#sa-administrative_area', 'Riyadh Region')
        ->type('#sa-city', 'Riyadh')
        ->type('#sa-district', 'Al Olaya')
        ->type('#sa-street', 'King Fahd Road')
        ->type('#sa-building', '7')
        ->click('[data-test="save-address-sa"]')
        // The first address in a country becomes its usual one on its own.
        ->assertSee('Usual Address')
        ->assertSee('King Fahd Road')
        ->assertNoJavaScriptErrors();
});

it('closes the account, and the shop forgets them at once', function () {
    [, $page] = shopSignedIn();

    $page->navigate('/sa/en/account?tab=close')
        // The tab is named with a noun; its button says what it does (owner, 2026-10-04).
        ->assertSeeIn('[data-test="tab-close"]', 'Account Closure')
        ->assertSee('Close Account')
        ->click('[data-test="close-account"]')
        // Out of reach until the password is typed: it is the dialog's typed gate (owner, 2026-10-04).
        ->assertDisabled('[data-test="confirm-close-account"]')
        ->type('#close_password', 'a long enough password')
        ->assertEnabled('[data-test="confirm-close-account"]')
        ->click('[data-test="confirm-close-account"]')
        // Back in the shop as a visitor: confirming ends every session of theirs at once, which
        // is why there is no cancel button anywhere in the account.
        ->assertPathIs('/sa/en')
        ->assertDontSee('Noura Saleh')
        ->assertSee('Sign In')
        ->assertNoJavaScriptErrors();
});

it('keeps the open tab in the address, and moves between the tabs with the arrow keys', function () {
    [, $page] = shopSignedIn();

    // The open tab is written into the address, so a refresh opens it again (Geist's Tabs).
    $page->navigate('/sa/en/account')
        ->click('[data-test="tab-phone"]')
        ->assertSee('Add Phone Number');

    expect($page->script('new URLSearchParams(window.location.search).get("tab")'))->toBe('phone');

    $page->navigate('/sa/en/account?tab=phone')
        ->assertSee('Add Phone Number')
        ->assertAttribute('[data-test="tab-phone"]', 'aria-selected', 'true');

    // Radix's tabs: the arrow keys move along them, upright, and open the one they reach.
    $page->keys('[data-test="tab-phone"]', 'ArrowDown')
        ->assertAttribute('[data-test="tab-addresses"]', 'aria-selected', 'true')
        ->assertSee('We deliver to the Usual Address in each store unless you pick another.')
        ->assertNoJavaScriptErrors();
});

it('sets another usual address and asks before deleting one, from the address\'s ⋯ menu', function () {
    [, $page] = shopSignedIn();

    $page->navigate('/sa/en/account?tab=addresses');

    foreach (['Home', 'Work'] as $label) {
        $page->click('[data-test="add-address-sa"]')
            ->type('#label-sa', $label)
            ->type('#recipient-sa', 'Noura Saleh')
            ->type('#phone-sa', '+966512345678')
            ->type('#sa-administrative_area', 'Riyadh Region')
            ->type('#sa-city', 'Riyadh')
            ->type('#sa-district', 'Al Olaya')
            ->type('#sa-street', "{$label} Street")
            ->type('#sa-building', '7')
            ->click('[data-test="save-address-sa"]')
            ->assertSee("{$label} Street");
    }

    // The second address is not the usual one: its menu offers to make it so, and the row says so
    // once it is done.
    $work = (string) $page->script('[...document.querySelectorAll(\'[data-test^="address-menu-"]\')].find((b) => b.getAttribute("aria-label").endsWith("Work")).dataset.test');
    $id = substr($work, strlen('address-menu-'));

    $page->click("[data-test=\"{$work}\"]")
        ->click("[data-test=\"make-default-{$id}\"]")
        ->assertSee('Usual address set')
        ->assertSeeIn("[data-test=\"address-{$id}\"]", 'Usual Address');

    // Delete Address… is last in the menu and asks first; Cancel hands focus back to the ⋯ button.
    $page->click("[data-test=\"{$work}\"]")
        ->click("[data-test=\"delete-address-{$id}\"]")
        ->assertSee('Orders already placed keep the address they were sent to. This cannot be undone.')
        ->click('[data-test="modal-cancel"]')
        ->assertMissing('[role="alertdialog"]');

    // Handed back once the dialog has finished closing, its animation and all.
    for ($tries = 0; $tries < 20 && $page->script('document.activeElement?.dataset.test') !== $work; $tries++) {
        $page->wait(0.1);
    }

    expect($page->script('document.activeElement?.dataset.test'))->toBe($work);

    // A phone (frontend.md §2.1): the addresses and an address's ⋯ menu fit, with nothing scrolling sideways.
    $page->resize(375, 812);
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the addresses scroll sideways');
    $page->click("[data-test=\"{$work}\"]")->assertPresent("[data-test=\"delete-address-{$id}\"]");
    expect(browserUntil($page, "(() => { const pop = document.querySelector('[role=\"menu\"]'); if (!pop || pop.getAnimations().length > 0) return false; const box = pop.getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth; })()"))->toBeTrue('the address\'s menu is wider than the screen');

    $page->assertNoJavaScriptErrors();
});
