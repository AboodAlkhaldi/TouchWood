<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\B2B\Application\Settings\BankAccountSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The Companies section of the settings page (b2b.md §2.3, amendment 13(c)): one line at its top says
| whether bank transfer is on in the store shown — on only while all three bank settings are filled.
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

/**
 * A staff member of the 'sa' store who may change its settings, signed in: the panel shows 'sa'.
 */
function b2bSettingsScreenSignIn(): AdminBrowser
{
    $staffId = Fx::staffWith([PlatformPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin);
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
 * The Companies section as the page receives it: its line, and its settings' keys.
 *
 * @return array{line: mixed, keys: list<mixed>}
 */
function b2bSettingsScreenSection(AdminBrowser $browser): array
{
    $section = ['line' => 'no section', 'keys' => []];

    $browser->get('/admin/settings')->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$section) {
        $groups = $inertia->toArray()['props']['groups'];

        foreach (is_array($groups) ? $groups : [] as $group) {
            if (is_array($group) && ($group['module'] ?? null) === 'b2b') {
                $settings = is_array($group['settings'] ?? null) ? $group['settings'] : [];
                $section = ['line' => $group['line'] ?? null, 'keys' => array_column($settings, 'key')];
            }
        }
    });

    return $section;
}

function b2bSettingsScreenFill(string $key, string $value): void
{
    app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, 'sa', $value));
}

it('says bank transfer is temporarily off while any of the three is empty, and on once all three are filled', function () {
    $browser = b2bSettingsScreenSignIn();

    $empty = b2bSettingsScreenSection($browser);
    b2bSettingsScreenFill(BankAccountSettings::IBAN, 'GB82 WEST 1234 5698 7654 32');
    b2bSettingsScreenFill(BankAccountSettings::BANK, 'Al Noor Bank');
    $two = b2bSettingsScreenSection($browser);
    b2bSettingsScreenFill(BankAccountSettings::HOLDER, 'TouchWood Trading');
    $three = b2bSettingsScreenSection($browser);

    expect($empty['keys'])->toBe([BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER])
        ->and($empty['line'])->toBe('Bank transfer: temporarily off — fill in all three to turn it on.')
        ->and($two['line'])->toBe('Bank transfer: temporarily off — fill in all three to turn it on.')
        ->and($three['line'])->toBe('Bank transfer: on');
});

it('gives other sections no line: only a module that registers one has it', function () {
    $staffId = Fx::staff(superAdmin: true);
    $browser = new AdminBrowser('10.11.2.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    $browser->get('/admin/settings')->assertOk()->assertInertia(function (AssertableInertia $inertia) {
        $groups = $inertia->toArray()['props']['groups'];
        $lines = [];

        foreach (is_array($groups) ? $groups : [] as $group) {
            if (is_array($group)) {
                // The key is always sent; null says the module has no line.
                $lines[(string) ($group['module'] ?? '')] = array_key_exists('line', $group) ? $group['line'] : 'not sent';
            }
        }

        expect($lines)->toHaveKeys(['platform', 'access'])
            ->and($lines['platform'])->toBeNull()
            ->and($lines['access'])->toBeNull();
    });
});
