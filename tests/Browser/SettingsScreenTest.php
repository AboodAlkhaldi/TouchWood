<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\B2B\Application\Settings\BankAccountSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Settings\SettingValues;
use Modules\Platform\Public\PlatformPermissions;
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
    // The browser suite keeps its data, so the setting starts from its default, with no stored row:
    // a 27 left by an earlier run would make typing 27 no change at all, and Cancel would never show.
    DB::table('platform.settings')->where('store_id', Fx::storeId('sa'))->where('key', $key)->delete();
    app(SettingValues::class)->invalidate();

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
    // Geist's Fieldset (owner, 2026-10-04): the box is open, and its Save Setting is out of reach
    // until the value changes; then Cancel appears beside it.
    $page->assertAttribute("[data-test=\"save-{$key}\"]", 'aria-disabled', 'true')
        ->assertMissing("[data-test=\"cancel-{$key}\"]")
        ->type("[id=\"{$key}\"]", '27')
        ->assertVisible("[data-test=\"cancel-{$key}\"]")
        ->click("[data-test=\"save-{$key}\"]")
        ->assertNoJavaScriptErrors();

    expect(DB::table('platform.settings')->where('key', $key)->value('value'))->toBe('27');
});

it('says in the Companies section whether bank transfer is on, and names no value for a setting still empty (b2b.md amendment 13(c))', function () {
    // The browser suite keeps its data, so the three start with no stored row — never set, their
    // default in force — and are left that way after. A stored '' would not do: a stored value is no
    // default, and the hint this proves hidden would be absent anyway (the review of step 5b).
    $empty = function (): void {
        DB::table('platform.settings')
            ->where('store_id', Fx::storeId('sa'))
            ->whereIn('key', [BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER])
            ->delete();
        app(SettingValues::class)->invalidate();
    };
    $empty();

    // An admin of one store who may change its store settings: only the Companies section shows.
    $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', SETTINGS_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]')
        ->assertPathIs('/admin')
        ->navigate('/admin/settings');

    $page->assertSee('Companies')
        ->assertSee('Bank transfer: temporarily off — fill in all three to turn it on.')
        // An empty default is "not set yet": no value in force to name (owner, 2026-09-29).
        ->assertDontSee('is in force')
        ->assertNoJavaScriptErrors();

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32'));
    app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::BANK, 'sa', 'Al Noor Bank'));
    app(UpdateSettingHandler::class)->handle(new UpdateSetting(BankAccountSettings::HOLDER, 'sa', 'TouchWood Trading'));

    $page->navigate('/admin/settings')
        ->assertSee('Bank transfer: on')
        ->assertDontSee('temporarily off')
        ->assertNoJavaScriptErrors();

    $empty();
});
