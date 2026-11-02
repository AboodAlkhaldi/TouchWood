<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Ops\Presentation\Http\Resource\SmsCountryOptions;
use Modules\Platform\Domain\ValueObject\IsoCountries;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| O10, the SMS Countries page (ops.md §4.15): a plain visit gets the page and JSON stays for whoever asks; a form
| the page sends goes back with its words, each refusal on its box. SmsCountryRoutesTest keeps the JSON answers.
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

function smsScreenStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.6.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/** @return array<string, string> */
function smsScreenInertia(): array
{
    return ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_REFERER' => url('/admin/sms-countries')];
}

/**
 * The words a form went back with.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, list<string>>
 */
function smsScreenErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

it('gives a plain visit the page, with the list, the providers and every country, and JSON to whoever asks', function () {
    $browser = smsScreenStaff(Fx::staff(superAdmin: true));

    $browser->get('/admin/sms-countries')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/SmsCountries')
        ->has('countries', 2)
        ->where('providers', ['log'])
        ->where('options', fn (Collection $options): bool => $options->count() === count(IsoCountries::codes()))
        ->where('translations', fn (Collection $words): bool => $words->has('ops::admin_sms.title') && $words->has('ops::errors.calling_code_taken.detail') && $words->has('admin.sidebar_toggle')));

    expect(array_keys($browser->get('/admin/sms-countries', ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json()))->toBe(['countries', 'providers']);
});

it('offers the same countries as Platform, in the page language', function () {
    $options = SmsCountryOptions::in('en');

    expect(array_column(array_map(static fn ($option) => $option->toArray(), $options), 'code'))->toEqualCanonicalizing(IsoCountries::codes())
        ->and(array_values(array_filter($options, static fn ($option): bool => $option->code === 'SA'))[0]->name)->toBe('Saudi Arabia')
        ->and(array_values(array_filter(SmsCountryOptions::in('ar'), static fn ($option): bool => $option->code === 'SA'))[0]->name)->toBe('المملكة العربية السعودية');
});

it('adds, changes and removes from the page, going back with its words', function () {
    $browser = smsScreenStaff(Fx::staff(superAdmin: true));

    $toast = static fn (TestResponse $response): mixed => AdminBrowser::flashed($response->assertRedirect('/admin/sms-countries'), 'status');

    expect($toast($browser->post('/admin/sms-countries', ['country_code' => 'AE', 'calling_code' => '971', 'provider' => 'log'], smsScreenInertia())))->toBe('Country added')
        ->and(DB::table('ops.sms_countries')->where('country_code', 'AE')->value('calling_code'))->toBe('971')
        ->and($toast($browser->post('/admin/sms-countries/AE/calling-code', ['calling_code' => '972'], smsScreenInertia())))->toBe('Calling code changed')
        ->and(DB::table('ops.sms_countries')->where('country_code', 'AE')->value('calling_code'))->toBe('972')
        ->and($toast($browser->post('/admin/sms-countries/AE/provider', ['provider' => 'log'], smsScreenInertia())))->toBe('Provider changed')
        ->and($toast($browser->post('/admin/sms-countries/AE/remove', [], smsScreenInertia())))->toBe('Country removed')
        ->and(DB::table('ops.sms_countries')->where('country_code', 'AE')->exists())->toBeFalse();
});

it('puts a value no box of that dialog shows in the form, so it is never lost', function () {
    $browser = smsScreenStaff(Fx::staff(superAdmin: true));

    expect(smsScreenErrors($browser->post('/admin/sms-countries/X1/remove', [], smsScreenInertia())))->toHaveKey('form')
        ->and(smsScreenErrors($browser->post('/admin/sms-countries/SA/provider', ['provider' => 'none'], smsScreenInertia())))->toHaveKey('provider');
});

it('puts each refusal on its box, in words, and changes nothing', function () {
    $browser = smsScreenStaff(Fx::staff(superAdmin: true));
    $add = static fn (array $fields): array => smsScreenErrors($browser->post('/admin/sms-countries', $fields, smsScreenInertia())->assertRedirect('/admin/sms-countries'));

    expect($add(['country_code' => 'SA', 'calling_code' => '977', 'provider' => 'log'])['country_code'][0] ?? '')->toContain('already on the SMS countries')
        ->and($add(['country_code' => 'AE', 'calling_code' => '966', 'provider' => 'log'])['calling_code'][0] ?? '')->toContain('already the code of')
        ->and($add(['country_code' => 'AE', 'calling_code' => '96', 'provider' => 'log'])['calling_code'][0] ?? '')->toContain('overlaps')
        ->and($add(['country_code' => 'AE', 'calling_code' => '971', 'provider' => 'none'])['provider'][0] ?? '')->toContain('provider');

    expect(DB::table('ops.sms_countries')->count())->toBe(2);
});

it('says a country gone in the form, not on a box', function () {
    $browser = smsScreenStaff(Fx::staff(superAdmin: true));

    expect(smsScreenErrors($browser->post('/admin/sms-countries/AE/remove', [], smsScreenInertia())->assertRedirect('/admin/sms-countries')))->toHaveKey('form');
});

it('offers the entry to Super Admins alone, and refuses anyone else the page', function () {
    $admin = smsScreenStaff(Fx::staffWith(['ops.message.send_one'], ['sa'], RoleLevel::Admin));

    $admin->get('/admin/sms-countries')->assertForbidden();
    expect(smsScreenErrors($admin->post('/admin/sms-countries', ['country_code' => 'AE', 'calling_code' => '971', 'provider' => 'log'], smsScreenInertia())->assertRedirect('/admin/sms-countries')))->toHaveKey('form')
        ->and(DB::table('ops.sms_countries')->count())->toBe(2);

    $menu = static function (AdminBrowser $who): array {
        /** @var array{props: array{menu: list<array{entries: list<array{key: string}>}>}} $page */
        $page = $who->get('/admin')->assertOk()->viewData('page');

        return array_merge(...array_map(static fn (array $group): array => array_column($group['entries'], 'key'), $page['props']['menu']));
    };

    expect($menu($admin))->not->toContain('sms_countries')
        ->and($menu(smsScreenStaff(Fx::staff(superAdmin: true))))->toContain('sms_countries');
});
