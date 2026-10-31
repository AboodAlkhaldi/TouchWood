<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AuthenticatorFixtures as App;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The authenticator app in a real browser (access.md amendment 91): setting it up after the SMS first
| sign-in - the password, the QR code, the first code, the recovery codes - and signing in with its
| code, or a recovery code instead. Phones are supported, so each screen is measured at 375 wide too.
|
| No RefreshDatabase, as the other browser files: every account here is this run's own. The methods
| are stored here, after tests/Pest.php has set SMS alone, so the next test starts from SMS again.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    Cache::flush();
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    App::methods(sms: false, authenticator: true);
});

function authenticatorScreensEmail(string $staffId): string
{
    return (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
}

it('sets the app up after the SMS first sign-in, and shows the recovery codes once', function () {
    $staffId = Fx::staff();
    $wide = 'document.documentElement.scrollWidth <= window.innerWidth';

    $page = visit('/admin/sign-in');
    $page->type('#email', authenticatorScreensEmail($staffId))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    // The panel waits: the setup comes first, asking the password before any QR code.
    expect(browserSoon($page, "window.location.pathname === '/admin/authenticator/setup' && document.querySelector('#current_password') !== null"))->toBeTrue('the setup page did not come first');
    expect($page->script("document.querySelector('[data-test=setup-qr]') === null"))->toBeTrue();

    $page->type('#current_password', Fx::STAFF_PASSWORD)->click('[data-test="show-qr"]');
    expect(browserSoon($page, "document.querySelector('[data-test=setup-key]') !== null"))->toBeTrue('the QR code did not appear');

    $page->resize(375, 812);
    expect(browserUntil($page, $wide))->toBeTrue('the setup page scrolls sideways on a phone');
    $page->resize(1280, 800);

    $secret = str_replace(' ', '', (string) $page->script("document.querySelector('[data-test=setup-key]').textContent"));
    $page->type('input[autocomplete="one-time-code"]', App::code($secret))->click('[data-test="confirm-app"]');

    expect(browserSoon($page, "document.querySelectorAll('[data-test=recovery-codes] li').length === 10"))->toBeTrue('the ten recovery codes were not shown');
    $page->resize(375, 812);
    expect(browserUntil($page, $wide))->toBeTrue('the recovery codes page scrolls sideways on a phone');
    $page->resize(1280, 800);

    $page->click('[data-test="codes-continue"]');
    expect(signedInToPanel($page))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('signs in with the app\'s code', function () {
    $staffId = Fx::staff();
    $app = App::giveApp($staffId);

    $page = visit('/admin/sign-in');
    $page->type('#email', authenticatorScreensEmail($staffId))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/app');

    $page->resize(375, 812);
    expect(browserUntil($page, "document.querySelector('input[autocomplete=\"one-time-code\"]') !== null && document.documentElement.scrollWidth <= window.innerWidth"))
        ->toBeTrue('the app code page scrolls sideways on a phone');
    $page->resize(1280, 800);

    $page->type('input[autocomplete="one-time-code"]', App::code($app['secret']))->click('button[type="submit"]');
    expect(signedInToPanel($page))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('signs in with a recovery code in place of the app\'s code', function () {
    $staffId = Fx::staff();
    $app = App::giveApp($staffId);

    $page = visit('/admin/sign-in');
    $page->type('#email', authenticatorScreensEmail($staffId))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/app')
        ->click('[data-test="switch-code-kind"]');
    expect(browserUntil($page, "document.querySelector('[data-test=recovery-code]') !== null"))->toBeTrue();
    $page->type('[data-test="recovery-code"]', $app['codes'][0])->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
