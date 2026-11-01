<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

/*
| The SMS countries over HTTP (ops.md §3, §4; access.md amendment 90): list, add, change a provider or a
| calling code, remove - Super Admins alone. The rules themselves are in SmsCountriesTest.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function smsRoutesStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

it('lists, adds, changes and removes a country as JSON, answering the whole list, audited', function () {
    $browser = smsRoutesStaff(Fx::staff(superAdmin: true));
    $json = ['HTTP_ACCEPT' => 'application/json'];

    $list = $browser->get('/admin/sms-countries', $json)->assertOk()->json();
    expect(array_column($list['countries'], 'countryCode'))->toEqualCanonicalizing(['SA', 'EG'])
        ->and($list['providers'])->toBe(['log'])
        ->and(array_keys($list['countries'][0]))->toBe(['countryCode', 'name', 'callingCode', 'provider']);

    $added = $browser->post('/admin/sms-countries', ['country_code' => 'AE', 'calling_code' => '971', 'provider' => 'log'])->assertCreated()->json('countries');
    expect(array_column($added, 'countryCode'))->toContain('AE');

    $changed = $browser->post('/admin/sms-countries/AE/calling-code', ['calling_code' => '972'])->assertOk()->json('countries');
    expect(array_column($changed, 'callingCode', 'countryCode')['AE'])->toBe('972');

    $browser->post('/admin/sms-countries/AE/provider', ['provider' => 'log'])->assertOk();

    $removed = $browser->post('/admin/sms-countries/AE/remove')->assertOk()->json('countries');
    expect(array_column($removed, 'countryCode'))->not->toContain('AE')
        ->and(DB::table('platform.audit_entries')->where('action', 'like', 'ops.sms_country.%')->count())->toBeGreaterThanOrEqual(3);
});

it('answers each refusal with its own status', function () {
    $browser = smsRoutesStaff(Fx::staff(superAdmin: true));

    $browser->post('/admin/sms-countries', ['country_code' => 'SA', 'calling_code' => '977', 'provider' => 'log'])->assertConflict();
    $browser->post('/admin/sms-countries', ['country_code' => 'AE', 'calling_code' => '966', 'provider' => 'log'])->assertConflict();
    $browser->post('/admin/sms-countries', ['country_code' => 'AE', 'calling_code' => '96', 'provider' => 'log'])->assertConflict();
    $browser->post('/admin/sms-countries', ['country_code' => 'AE', 'calling_code' => '971', 'provider' => 'none'])->assertUnprocessable();
    $browser->post('/admin/sms-countries/AE/remove')->assertNotFound();
    $browser->post('/admin/sms-countries/AE/provider', ['provider' => 'log'])->assertNotFound();

    expect(DB::table('ops.sms_countries')->count())->toBe(2);
});

it('opens to Super Admins alone: an admin holding everything else, and anyone signed out, are refused', function () {
    getJson('/admin/sms-countries')->assertUnauthorized();

    $browser = smsRoutesStaff(Fx::staffWith(['ops.message.send_one'], ['sa'], RoleLevel::Admin));
    $json = ['HTTP_ACCEPT' => 'application/json'];

    $browser->get('/admin/sms-countries', $json)->assertForbidden();
    $browser->post('/admin/sms-countries', ['country_code' => 'AE', 'calling_code' => '971', 'provider' => 'log'])->assertForbidden();
    $browser->post('/admin/sms-countries/SA/provider', ['provider' => 'log'])->assertForbidden();
    $browser->post('/admin/sms-countries/SA/calling-code', ['calling_code' => '966'])->assertForbidden();
    $browser->post('/admin/sms-countries/SA/remove')->assertForbidden();

    expect(DB::table('ops.sms_countries')->count())->toBe(2);
});
