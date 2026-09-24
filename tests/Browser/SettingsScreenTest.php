<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The settings screen, in a real browser (frontend.md §3.5, E4).
|
| The tests beside these prove which rows a person is given. This one proves the screen is usable
| with them: that each row saves on its own, and that a person who may change one module's settings
| sees that module's section and no other.
*/

const SETTINGS_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('saves one setting on its own, leaving the rest of the screen alone', function () {
    // An admin of one store who may change that store's customer numbers and nothing else.
    $staffId = Fx::staffWith([AccessPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');
    $key = CustomerSecuritySettings::LOCKOUT_MINUTES;

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', SETTINGS_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        // Waited for, or the redirect this click starts and the navigate below race each other
        // and the browser abandons one of them (found by running it, 2026-09-24).
        ->assertPathIs('/admin')
        ->navigate('/admin/settings');

    // Access's section, because Access declared these; Platform's media settings are global and
    // this person does not reach every store, so that section is not on the screen at all.
    $page->assertSee('Settings')
        ->assertSee('Access')
        ->assertDontSee('Platform')
        ->assertNoJavaScriptErrors();

    // Each row is its own form, so this saves one number and answers about that number.
    //
    // Addressed by attribute rather than by "#id": a setting's key has dots in it, and
    // "#access.customer.lockout_minutes" is a CSS selector for an id with two classes on it.
    $page->type("[id=\"{$key}\"]", '27')
        ->click("[data-test=\"save-{$key}\"]")
        ->assertNoJavaScriptErrors();

    expect(DB::table('platform.settings')->where('key', $key)->value('value'))->toBe('27');
});
