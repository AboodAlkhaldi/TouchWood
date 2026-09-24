<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The sign-in screen, in a real browser (stage 2b, step 2).
|
| Every other suite asks the server what it would send. This one asks whether a person can use the
| answer - a different question, and the one that matters. Step 1's suite was green while every page
| rendered blank, because the server was sending a perfectly good page that the browser then failed
| to bring to life.
|
| So these are about what a person sees and does, not about what a handler returns.
*/

// Deliberately no RefreshDatabase. The suite serves the application in this process, but a served
// request opens its own connection, so it cannot see a transaction wrapped round the test - and the
// two deadlock against each other instead (found by running it, 2026-09-23). These tests therefore
// leave the database as they found it by making their own data unique, not by rolling it back.

beforeEach(function () {
    // The browser tests serve the application inside this very process, so they inherit the
    // suite's session driver - and phpunit.xml forces "array", which keeps nothing between
    // requests. A refusal flashed on a redirect was therefore gone by the time the page rendered,
    // and the screen looked as though nothing had happened (found by running it, 2026-09-22).
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('draws the sign-in screen, rather than merely answering 200', function () {
    // The white-page bug in one assertion. The server answered 200 and nothing appeared; a test of
    // the response passed, and a person saw nothing. This is what that costs us now.
    visit('/admin/sign-in')
        ->assertSee('تسجيل الدخول')
        ->assertSee('بريد العمل')
        ->assertNoJavaScriptErrors();
});

it('turns the page around for Arabic, not only the words', function () {
    // Read off the document itself: whether the layout mirrors is decided by <html dir>, and a
    // screen can be perfectly translated while still running the wrong way.
    $page = visit('/admin/sign-in');

    expect($page->script('document.documentElement.dir'))->toBe('rtl')
        ->and($page->script('document.documentElement.lang'))->toBe('ar');
});

it('says why a sign-in was refused, where the person is looking', function () {
    // It used to say it nowhere a person would look: a toast at the foot of a tall page that faded
    // after six seconds, and nothing at all on a second attempt.
    visit('/admin/sign-in')
        ->type('#email', 'nobody@touchwood.test')
        ->type('#password', 'not-the-right-password-at-all')
        // The submit button, not the heading above it - both say the same words, and pressing by
        // text found the heading and did nothing at all (found by running it, 2026-09-22).
        ->click('button[type="submit"]')
        ->assertSee('البريد الإلكتروني أو كلمة المرور غير صحيحة.');
});

it('lets a person see the password they are typing', function () {
    $page = visit('/admin/sign-in');

    expect($page->script('document.querySelector("#password").type'))->toBe('password');

    // The eye carries no text of its own, only a label, so it is found by the state it announces.
    $page->click('button[aria-pressed]');

    expect($page->script('document.querySelector("#password").type'))->toBe('text');
});

it('takes a code typed on an Arabic keyboard, and signs the person in', function () {
    // The boxes accepted ٠١٢ and sent them on unchanged, so a person who typed their code
    // correctly on an Arabic keyboard was refused for it: a code is compared as a hash, and a hash
    // of ٠٥٩ is not a hash of 059 (frontend.md 1.8).
    $staffId = Fx::staff();
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', 'a long enough password')
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code');

    $code = RecordingSecurityMessages::installed()->lastCode();
    $arabic = strtr($code, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

    expect($arabic)->not->toBe($code);

    $page->type('input[autocomplete="one-time-code"]', $arabic)
        ->click('button[type="submit"]')
        // Signed in: the panel, not the code screen saying the code was wrong.
        ->assertPathIs('/admin')
        ->assertNoJavaScriptErrors();
});

it('will not send a new password while the two boxes differ', function () {
    // The same rule as the shop's reset page, and for the same reason: the endpoint takes
    // `password` alone, so the second box is the page's to check or nobody's (owner, 2026-09-24).
    // The token is not read by the page, so any token draws it. The panel is read in Arabic with
    // no cookie to say otherwise, and the words are asked for in that language rather than in
    // whichever one the last served request left on the application.
    $page = visit('/admin/password/reset/a-token-this-page-never-reads');

    $page->type('#password', 'a long enough password')
        ->type('#password_repeat', 'a long enough passwerd')
        ->assertSee((string) __('access::auth.passwords_differ', [], 'ar'))
        ->assertDisabled('[data-test="save-password"]');

    $page->clear('#password_repeat')
        ->type('#password_repeat', 'a long enough password')
        ->assertDontSee((string) __('access::auth.passwords_differ', [], 'ar'))
        ->assertEnabled('[data-test="save-password"]')
        ->assertNoJavaScriptErrors();
});
