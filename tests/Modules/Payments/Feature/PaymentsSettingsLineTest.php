<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeys;
use Modules\Payments\Application\Command\ChangeKeys\ChangeKeysHandler;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGateway;
use Modules\Payments\Application\Command\SwitchGateway\SwitchGatewayHandler;
use Modules\Payments\Application\Command\SwitchMode\SwitchMode;
use Modules\Payments\Application\Command\SwitchMode\SwitchModeHandler;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\Settings\BankAccountSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The Payments section of the settings page (payments.md §1.2, §1.9, gap 8.8): one line at its top says how
| the store shown takes money, online and by transfer.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

const PAYMENTS_LINE_OFF = 'Online payment: off — individuals pay through staff until a gateway is set up';

const PAYMENTS_LINE_NO_TRANSFER = 'Bank transfer: temporarily off — fill in all three to turn it on.';

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    app()->instance(KeyTester::class, new class implements KeyTester
    {
        public function test(string $apiBase, #[SensitiveParameter] string $key, string $currencyCode): void {}
    });
});

/**
 * An admin of the 'sa' store who may change its settings and its bank account, signed in: the panel shows
 * 'sa'.
 */
function paymentsLineSignIn(): AdminBrowser
{
    $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_UPDATE, PaymentsPermissions::BANK_ACCOUNT_UPDATE], ['sa'], RoleLevel::Admin);
    $browser = new AdminBrowser('10.11.1.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

/**
 * The page's sections as it receives them: each one's line, and its settings' keys.
 *
 * @return array<string, array{line: mixed, keys: list<mixed>}>
 */
function paymentsLineSections(AdminBrowser $browser): array
{
    $sections = [];

    $browser->get('/admin/settings')->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$sections) {
        $groups = $inertia->toArray()['props']['groups'];

        foreach (is_array($groups) ? $groups : [] as $group) {
            if (is_array($group)) {
                $settings = is_array($group['settings'] ?? null) ? $group['settings'] : [];
                $sections[(string) ($group['module'] ?? '')] = ['line' => $group['line'] ?? null, 'keys' => array_column($settings, 'key')];
            }
        }
    });

    return $sections;
}

function paymentsLineFill(string $key, string $value): void
{
    app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, 'sa', $value));
}

it('says bank transfer is temporarily off while any of the three is empty, and on once all three are filled', function () {
    $browser = paymentsLineSignIn();

    $empty = paymentsLineSections($browser);
    paymentsLineFill(BankAccountSettings::IBAN, 'GB82 WEST 1234 5698 7654 32');
    paymentsLineFill(BankAccountSettings::BANK, 'Al Noor Bank');
    $two = paymentsLineSections($browser);
    paymentsLineFill(BankAccountSettings::HOLDER, 'TouchWood Trading');
    $three = paymentsLineSections($browser);

    expect($empty['payments']['keys'])->toContain(BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER)
        ->and($empty['payments']['line'])->toBe(PAYMENTS_LINE_OFF.' · '.PAYMENTS_LINE_NO_TRANSFER)
        ->and($two['payments']['line'])->toBe(PAYMENTS_LINE_OFF.' · '.PAYMENTS_LINE_NO_TRANSFER)
        ->and($three['payments']['line'])->toBe(PAYMENTS_LINE_OFF.' · Bank transfer: on');
});

it('says how the store takes online payment: off, test, live, or stopped by a refused key', function () {
    $browser = paymentsLineSignIn();
    $store = Fx::storeId('sa');
    $line = fn (): mixed => paymentsLineSections($browser)['payments']['line'];
    $online = [$line()];

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'TEST', 'sk_test_key_ABC123', 'whsec_secret_1', '2027-12-31'));
    // A key alone turns nothing on: the gateway starts switched off.
    $online[] = $line();

    app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, true));
    $online[] = $line();

    app(ChangeKeysHandler::class)->handle(new ChangeKeys($store, 'LIVE', 'sk_live_key_ABC123', 'whsec_secret_2', '2027-12-31'));
    app(SwitchModeHandler::class)->handle(new SwitchMode($store, 'LIVE'));
    $online[] = $line();

    DB::table('payments.gateway_keys')->where('current', true)->where('mode', 'LIVE')->update(['rejected_at' => now()]);
    $online[] = $line();

    app(SwitchModeHandler::class)->handle(new SwitchMode($store, 'TEST'));
    DB::table('payments.gateway_keys')->where('current', true)->where('mode', 'TEST')->update(['rejected_at' => now()]);
    $online[] = $line();

    app(SwitchGatewayHandler::class)->handle(new SwitchGateway($store, false));
    $online[] = $line();

    expect($online)->toBe(array_map(static fn (string $words): string => $words.' · '.PAYMENTS_LINE_NO_TRANSFER, [
        PAYMENTS_LINE_OFF,
        PAYMENTS_LINE_OFF,
        'Online payment: TEST mode — no real money is taken',
        'Online payment: on',
        'Online payment: stopped — MyFatoorah refused the key; individuals pay through staff until a new one is saved',
        'Online payment: stopped — MyFatoorah refused the key; individuals pay through staff until a new one is saved',
        PAYMENTS_LINE_OFF,
    ]));
});

it('shows an admin holding only the bank account\'s job its three fields and the line', function () {
    $staffId = Fx::staffWith([PaymentsPermissions::BANK_ACCOUNT_UPDATE], ['sa'], RoleLevel::Admin);
    $browser = new AdminBrowser('10.11.3.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    $section = paymentsLineSections($browser)['payments'];

    expect($section['keys'])->toBe([BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER])
        ->and($section['line'])->toBe(PAYMENTS_LINE_OFF.' · '.PAYMENTS_LINE_NO_TRANSFER);
});

it('keeps the Companies section\'s form minimums, with no bank account and no line', function () {
    $sections = paymentsLineSections(paymentsLineSignInSuperAdmin());

    expect($sections['b2b']['line'])->toBeNull()
        ->and($sections['b2b']['keys'])->not->toContain(BankAccountSettings::IBAN, 'b2b.bank.iban', 'b2b.bank.name', 'b2b.bank.holder')
        ->and($sections['b2b']['keys'])->toHaveCount(5);
});

it('gives other sections no line: only a module that registers one has it', function () {
    $sections = paymentsLineSections(paymentsLineSignInSuperAdmin());

    expect($sections)->toHaveKeys(['platform', 'access'])
        ->and($sections['platform']['line'])->toBeNull()
        ->and($sections['access']['line'])->toBeNull();
});

function paymentsLineSignInSuperAdmin(): AdminBrowser
{
    $staffId = Fx::staff(superAdmin: true);
    $browser = new AdminBrowser('10.11.2.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}
