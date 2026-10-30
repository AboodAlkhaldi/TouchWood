<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Public\Contracts\PhoneCountries;
use Modules\Ops\Application\Command\AddSmsCountry\AddSmsCountry;
use Modules\Ops\Application\Command\AddSmsCountry\AddSmsCountryHandler;
use Modules\Ops\Application\Command\ChangeSmsCountryCallingCode\ChangeSmsCountryCallingCode;
use Modules\Ops\Application\Command\ChangeSmsCountryCallingCode\ChangeSmsCountryCallingCodeHandler;
use Modules\Ops\Application\Command\ChangeSmsCountryProvider\ChangeSmsCountryProvider;
use Modules\Ops\Application\Command\ChangeSmsCountryProvider\ChangeSmsCountryProviderHandler;
use Modules\Ops\Application\Command\RemoveSmsCountry\RemoveSmsCountry;
use Modules\Ops\Application\Command\RemoveSmsCountry\RemoveSmsCountryHandler;
use Modules\Ops\Application\Messages\ListedPhoneCountries;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\ListSmsCountries\ListSmsCountries;
use Modules\Ops\Application\Query\ListSmsCountries\ListSmsCountriesHandler;
use Modules\Ops\Application\Query\ListSmsCountries\SmsCountryRow;
use Modules\Ops\Domain\Exception\CallingCodeTaken;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Domain\Exception\SmsCountryNotFound;
use Modules\Ops\Domain\Exception\SmsCountryTaken;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Ops\Support\RecordingSmsSenders;

use function Pest\Laravel\seed;

/*
| The SMS countries (owner, 2026-10-29; access.md amendment 90): which countries' phones SMS reaches,
| each one's calling code - typed by a Super Admin - and its provider. Super Admins only, audited. The
| start list is Saudi Arabia 966 and Egypt 20, both on `log`.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

/**
 * The list as the page shows it: "code calling-code provider", by English name.
 *
 * @return list<string>
 */
function smsCountriesListed(string $locale = 'en'): array
{
    return array_map(
        static fn (SmsCountryRow $row): string => "{$row->countryCode} {$row->callingCode} {$row->provider}",
        app(ListSmsCountriesHandler::class)->handle(new ListSmsCountries($locale))->countries,
    );
}

function smsCountriesAdd(string $country, string $callingCode, string $provider = 'log'): void
{
    app(AddSmsCountryHandler::class)->handle(new AddSmsCountry($country, $callingCode, $provider));
}

it('starts with Saudi Arabia and Egypt on the log provider, named in both languages', function () {
    $list = app(ListSmsCountriesHandler::class)->handle(new ListSmsCountries('en'));

    expect(smsCountriesListed())->toBe(['EG 20 log', 'SA 966 log'])
        ->and($list->providers)->toBe(['log'])
        ->and($list->countries[1]->name->en)->toBe('Saudi Arabia')
        ->and($list->countries[1]->name->ar)->toBe('المملكة العربية السعودية');
});

it('adds a country with its typed calling code and a provider Ops has, audited', function () {
    smsCountriesAdd(' ae ', '٩٧١');

    expect(smsCountriesListed())->toBe(['EG 20 log', 'SA 966 log', 'AE 971 log'])
        ->and(Fx::audits('ops.sms_country.added', 'AE'))->toBe(1)
        ->and(app(PhoneCountries::class)->accepts('+971501234567'))->toBeTrue();
});

it('refuses what is not a country, a calling code or a provider Ops has', function (string $country, string $callingCode, string $provider, string $field) {
    expect(fn () => smsCountriesAdd($country, $callingCode, $provider))
        ->toThrow(InvalidOpsInput::class, "Invalid {$field}:");

    expect(smsCountriesListed())->toBe(['EG 20 log', 'SA 966 log']);
})->with([
    'no such country' => ['QQ', '971', 'log', 'country_code'],
    'ICU\'s unknown region' => ['ZZ', '971', 'log', 'country_code'],
    'three letters' => ['ARE', '971', 'log', 'country_code'],
    'a plus sign' => ['AE', '+971', 'log', 'calling_code'],
    'four digits' => ['AE', '9710', 'log', 'calling_code'],
    'a leading 0' => ['AE', '071', 'log', 'calling_code'],
    'letters' => ['AE', '97a', 'log', 'calling_code'],
    'a provider not built' => ['AE', '971', 'mobily', 'provider'],
]);

it('refuses a country already listed, and a calling code another country has', function () {
    expect(fn () => smsCountriesAdd('SA', '967'))->toThrow(SmsCountryTaken::class)
        ->and(fn () => smsCountriesAdd('AE', '966'))->toThrow(CallingCodeTaken::class, 'already the code of "SA"')
        ->and(Fx::audits('ops.sms_country.added'))->toBe(0);
});

it('changes a country\'s provider, one provider serving many countries', function () {
    RecordingSmsSenders::install();

    app(ChangeSmsCountryProviderHandler::class)->handle(new ChangeSmsCountryProvider('eg', 'second'));
    // The same provider again changes nothing and is not audited.
    app(ChangeSmsCountryProviderHandler::class)->handle(new ChangeSmsCountryProvider('EG', 'second'));

    expect(smsCountriesListed())->toBe(['EG 20 second', 'SA 966 log'])
        ->and(Fx::audits('ops.sms_country.provider_changed', 'EG'))->toBe(1)
        ->and(fn () => app(ChangeSmsCountryProviderHandler::class)->handle(new ChangeSmsCountryProvider('EG', 'mobily')))->toThrow(InvalidOpsInput::class)
        ->and(fn () => app(ChangeSmsCountryProviderHandler::class)->handle(new ChangeSmsCountryProvider('AE', 'log')))->toThrow(SmsCountryNotFound::class);
});

it('corrects a calling code, refusing one another country has', function () {
    smsCountriesAdd('AE', '972');

    app(ChangeSmsCountryCallingCodeHandler::class)->handle(new ChangeSmsCountryCallingCode('AE', '971'));

    expect(smsCountriesListed())->toBe(['EG 20 log', 'SA 966 log', 'AE 971 log'])
        ->and(Fx::audits('ops.sms_country.calling_code_changed', 'AE'))->toBe(1)
        ->and(fn () => app(ChangeSmsCountryCallingCodeHandler::class)->handle(new ChangeSmsCountryCallingCode('AE', '966')))->toThrow(CallingCodeTaken::class)
        ->and(fn () => app(ChangeSmsCountryCallingCodeHandler::class)->handle(new ChangeSmsCountryCallingCode('AE', '0')))->toThrow(InvalidOpsInput::class)
        ->and(app(PhoneCountries::class)->accepts('+972501234567'))->toBeFalse();
});

it('removes a country even while saved phones use it: from then on its numbers are refused', function () {
    $customerId = Fx::customer();
    DB::table('access.customers')->where('id', $customerId)->update(['phone' => '+201001234567', 'phone_verified_at' => now()]);

    app(RemoveSmsCountryHandler::class)->handle(new RemoveSmsCountry('EG'));

    expect(smsCountriesListed())->toBe(['SA 966 log'])
        ->and(Fx::audits('ops.sms_country.removed', 'EG'))->toBe(1)
        ->and(app(PhoneCountries::class)->accepts('+201001234567'))->toBeFalse()
        ->and(app(PhoneCountries::class)->accepts('+966501234567'))->toBeTrue()
        ->and(fn () => app(RemoveSmsCountryHandler::class)->handle(new RemoveSmsCountry('EG')))->toThrow(SmsCountryNotFound::class);
});

it('reads a number\'s country by the calling code it starts with, the longest when two would match', function () {
    // Calling codes are prefix-free (ITU); a list typed by hand might not be.
    DB::table('ops.sms_countries')->insert(['country_code' => 'US', 'calling_code' => '9', 'provider' => 'log', 'created_at' => now(), 'updated_at' => now()]);
    $countries = app(ListedPhoneCountries::class);

    expect($countries->countryOf('+966501234567')?->countryCode)->toBe('SA')
        ->and($countries->countryOf('+971501234567')?->countryCode)->toBe('US')
        ->and($countries->countryOf('+201001234567')?->countryCode)->toBe('EG')
        ->and($countries->countryOf('+441234567890'))->toBeNull();
});

it('reads the list once for every phone it is asked about', function () {
    $countries = app(ListedPhoneCountries::class);
    DB::flushQueryLog();
    DB::enableQueryLog();

    foreach (range(1, 50) as $i) {
        $countries->accepts('+9665'.str_pad((string) $i, 8, '0', STR_PAD_LEFT));
    }

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1);
});

it('is the Super Admins\' alone: an admin may neither read nor change it', function () {
    Fx::actAsStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));

    expect(fn () => smsCountriesListed())->toThrow(Unauthorized::class)
        ->and(fn () => smsCountriesAdd('AE', '971'))->toThrow(Unauthorized::class)
        ->and(fn () => app(ChangeSmsCountryProviderHandler::class)->handle(new ChangeSmsCountryProvider('SA', 'log')))->toThrow(Unauthorized::class)
        ->and(fn () => app(ChangeSmsCountryCallingCodeHandler::class)->handle(new ChangeSmsCountryCallingCode('SA', '967')))->toThrow(Unauthorized::class)
        ->and(fn () => app(RemoveSmsCountryHandler::class)->handle(new RemoveSmsCountry('SA')))->toThrow(Unauthorized::class);
});

it('keeps the table\'s own rules as the last line', function (array $row, string $constraint) {
    expect(fn () => DB::table('ops.sms_countries')->insert([...['country_code' => 'AE', 'calling_code' => '971', 'provider' => 'log', 'created_at' => now(), 'updated_at' => now()], ...$row]))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'a lowercase code' => [['country_code' => 'ae'], 'sms_countries_country_code'],
    'a leading 0' => [['calling_code' => '071'], 'sms_countries_calling_code'],
    'a plus sign' => [['calling_code' => '+97'], 'sms_countries_calling_code'],
    'a provider\'s name with spaces' => [['provider' => 'my sms'], 'sms_countries_provider'],
    'a taken calling code' => [['calling_code' => '966'], 'sms_countries_calling_code_unique'],
]);

it('names every action it audits, in both languages', function () {
    foreach (['added', 'provider_changed', 'calling_code_changed', 'removed'] as $action) {
        foreach (['ar', 'en'] as $locale) {
            $key = "ops::audit.sms_country.{$action}";

            expect(trans($key, [], $locale))->not->toBe($key);
        }
    }

    $ar = require dirname(__DIR__, 4).'/src/Modules/Ops/Presentation/lang/ar/audit.php';
    $en = require dirname(__DIR__, 4).'/src/Modules/Ops/Presentation/lang/en/audit.php';

    expect(array_keys($ar))->toBe(array_keys($en));
});
