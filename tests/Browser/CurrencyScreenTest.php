<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The currencies screen, in a real browser (frontend.md §3.5, E3).
|
| The sign preview is the reason this test exists. A sign is shown in the shop's own font before it
| is saved, so a sign the font cannot draw is seen here rather than in a customer's basket
| (platform.md §5.1) - and only a browser can show whether that preview really draws.
*/

const CURRENCY_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('draws the currencies, shows a sign as a price will, and settles the decimal places in use', function () {
    $email = (string) DB::table('access.staff_users')
        ->where('id', Fx::staff(superAdmin: true))
        ->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', CURRENCY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/currencies');

    $page->assertSee('Currencies')
        ->assertSee('SAR')
        ->assertNoJavaScriptErrors();

    // The riyal: one store charges in it, so its decimal places are settled and the screen says
    // why rather than offering a change that would be refused.
    $page->click('[data-test="edit-SAR"]')
        ->assertSee('Settled')
        ->assertSee('As a price will show it')
        ->assertNoJavaScriptErrors();

    // Typed, and drawn at once: this is the whole point of the field.
    $page->type('#SAR-sign', 'ر.س')->assertSee('ر.س');

    expect($page->script('document.querySelector("#SAR-exponent").disabled'))->toBeTrue();
});

it('adds a currency from the screen', function () {
    $email = (string) DB::table('access.staff_users')
        ->where('id', Fx::staff(superAdmin: true))
        ->value('email');

    // Unique, because these tests leave the database as they found it by not colliding with it -
    // and letters only, because a currency code is three letters and Str::random gives digits too.
    $code = collect(range('A', 'Z'))->shuffle()->take(3)->implode('');
    $name = 'Currency '.Str::random(6);

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', CURRENCY_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->navigate('/admin/currencies');

    // Named: the panel's own header carries buttons too, and "header button" finds one of those.
    $page->click('[data-test="add-currency"]')
        ->type('#new-code', $code)
        ->type('#new-name_ar', 'عملة')
        ->type('#new-name_en', $name)
        ->type('#new-abbreviation_ar', 'ع')
        ->type('#new-abbreviation_en', $code)
        ->click('[data-test="create-currency"]')
        ->assertSee($name)
        ->assertNoJavaScriptErrors();

    expect(DB::table('platform.currencies')->where('code', $code)->exists())->toBeTrue();
});
