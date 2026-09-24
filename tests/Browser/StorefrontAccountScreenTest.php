<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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
        ->assertSee((string) __('access::auth.verification_sent'))
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
        ->assertSee((string) __('access::errors.email_already_registered.detail'))
        ->assertNoJavaScriptErrors();
});
