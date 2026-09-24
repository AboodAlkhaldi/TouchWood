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

    $page->assertSee('Create an account')
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
        ->assertSee('Confirm your email')
        ->assertNoJavaScriptErrors();
});

it('shows an unconfirmed customer where their link went, and offers another', function () {
    $email = shopAddress();

    $page = visit('/sa/en/register');
    $page->type('#first_name', 'Noura')
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
    $page->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en')
        ->assertSee('Noura Saleh');

    $page->click('[data-test="sign-out"]')
        ->assertDontSee('Noura Saleh')
        ->assertSee('Sign in')
        ->assertNoJavaScriptErrors();
});

it('refuses a registration in the shop\'s own words, on the form', function () {
    // A refusal that appears only as a toast at the foot of the page, for six seconds, reads as
    // nothing having happened at all (step 1's own lesson).
    $email = shopAddress();

    $first = visit('/sa/en/register');
    $first->type('#first_name', 'Noura')
        ->type('#last_name', 'Saleh')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en');

    // The same address again, from a browser that is not signed in.
    $second = visit('/sa/en/sign-in');
    $second->navigate('/sa/en/register')
        ->type('#first_name', 'Someone')
        ->type('#last_name', 'Else')
        ->type('#email', $email)
        ->type('#password', 'another long password')
        ->click('[data-test="terms"]')
        ->click('button[type="submit"]')
        ->assertPathIs('/sa/en/register')
        ->assertSee((string) __('access::errors.email_already_registered.detail', [], 'en'))
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
    $page->type('#first_name', 'Noura')
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

    $page->click('[data-test="my-account"]')
        ->assertPathIs('/sa/en/account')
        ->assertSee('My account')
        // Their own address, which they cannot change, said where it is rather than refused later.
        ->assertSee($email)
        ->assertSee('Your email address cannot be changed.')
        // Nothing verified yet, so this is the page that says what ordering waits for.
        ->assertSeeIn('[data-test="before-ordering"]', 'Before you can order')
        ->assertNoJavaScriptErrors();
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

    $page->navigate('/sa/en/account')
        ->click('[data-test="tab-phone"]')
        ->assertSee('No number yet.')
        ->type('#phone', '+966512345678')
        ->click('[data-test="send-phone-code"]')
        // The second step only appears once the server says a code went out.
        ->type('#phone_code', RecordingSecurityMessages::installed()->lastCode())
        ->click('[data-test="confirm-phone"]')
        ->assertSee('+966512345678')
        ->assertNoJavaScriptErrors();
});
