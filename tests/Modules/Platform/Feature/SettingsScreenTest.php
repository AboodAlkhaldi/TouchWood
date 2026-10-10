<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| Stage 2b, step 3 - the settings screen over HTTP (frontend.md §3.5, E4).
|
| Every helper here is named after this file's subject. A function declared in a Pest file is global
| to the whole suite, so two files sharing a name stop every run (project conventions).
*/

function settingsScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.11.0.'.random_int(20, 250));

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
 * Whether the answer refused the setting's value. The page shows a refused setting's message above
 * the form - "Couldn't save … the value isn't valid" - as it does for a number out of bounds.
 *
 * @param  TestResponse<Response>  $response
 */
function settingsScreenRefusedValue(TestResponse $response): bool
{
    return AdminBrowser::formError($response) !== null;
}

describe('the settings screen', function () {
    it('puts a module\'s settings in one section, whatever permissions they carry', function () {
        $browser = settingsScreenSignIn(Fx::staff(superAdmin: true));

        $browser->get('/admin/settings')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $inertia) {
                $inertia->component('Platform/Admin/Settings/Index');

                /** @var list<array{module: string}> $groups */
                $groups = $inertia->toArray()['props']['groups'];
                $modules = array_column($groups, 'module');

                // Access's staff numbers are admin-only and global; its customer numbers are
                // ordinary and per-store. One section all the same (owner, 2026-09-22).
                expect($modules)->toBe(array_unique($modules))
                    ->and($modules)->toContain('access')
                    ->and($modules)->toContain('platform');
            });
    });

    it('saves a setting, and says which store it applies to', function () {
        $staffId = Fx::staffWith([AccessPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin);
        $browser = settingsScreenSignIn($staffId);

        $key = CustomerSecuritySettings::LOCKOUT_MINUTES;

        // A store's own setting names its store, the one the page's filter shows (platform.md
        // §9.10 #3): without it nothing is saved; another store's is refused.
        $missing = $browser->post("/admin/settings/{$key}", ['value' => '20']);
        $elsewhere = $browser->post("/admin/settings/{$key}", ['value' => '20', 'store' => 'ae']);

        expect($missing->status())->toBeLessThan(500)
            ->and($elsewhere->status())->toBeLessThan(500)
            ->and(DB::table('platform.settings')->where('key', $key)->exists())->toBeFalse();

        $browser->post("/admin/settings/{$key}", ['value' => '20', 'store' => 'sa'])->assertRedirect();

        $row = DB::table('platform.settings')->where('key', $key)->first()
            ?? throw new RuntimeException('The setting was not stored.');

        expect($row->store_id)->toBe(Fx::storeId('sa'))
            ->and(json_decode((string) $row->value, true))->toBe(20);
    });

    it('refuses a number outside the bounds the module declared, and changes nothing', function () {
        $browser = settingsScreenSignIn(Fx::staff(superAdmin: true));

        // The staff lockout allows 3 to 20 (StaffSecuritySettings).
        $key = StaffSecuritySettings::LOCKOUT_ATTEMPTS;
        $browser->post("/admin/settings/{$key}", ['value' => '999'])->assertRedirect();

        expect(DB::table('platform.settings')->where('key', $key)->exists())->toBeFalse();
    });

    // Loyalty step 1's review (owner, 2026-10-10): an emptied number field used to arrive as 0, and
    // Loyalty's "most of an order points may pay" takes 0 - so clearing it and pressing Save turned
    // redemption off while the page said "Saved".
    it('refuses an emptied number field, or text that is not a whole number, and keeps the value', function (string $sent) {
        $browser = settingsScreenSignIn(Fx::staff(superAdmin: true));
        $key = ProgrammeSettings::MAX_REDEMPTION_PERCENT;

        $saved = $browser->post("/admin/settings/{$key}", ['value' => '40', 'store' => 'sa'])->assertRedirect();
        $refused = $browser->post("/admin/settings/{$key}", ['value' => $sent, 'store' => 'sa'])->assertRedirect();

        expect(settingsScreenRefusedValue($saved))->toBeFalse()
            ->and(settingsScreenRefusedValue($refused))->toBeTrue();

        $row = DB::table('platform.settings')->where('key', $key)->where('store_id', Fx::storeId('sa'))->first()
            ?? throw new RuntimeException('The setting was not stored.');

        expect(json_decode((string) $row->value, true))->toBe(40);
    })->with([
        'emptied' => [''],
        'spaces' => ['   '],
        'letters' => ['abc'],
        'a number and letters' => ['12abc'],
        'a fraction' => ['1.5'],
    ]);

    it('takes a whole number written with spaces around it', function () {
        $browser = settingsScreenSignIn(Fx::staff(superAdmin: true));
        $key = ProgrammeSettings::MAX_REDEMPTION_PERCENT;

        $saved = $browser->post("/admin/settings/{$key}", ['value' => ' 35 ', 'store' => 'sa'])->assertRedirect();

        expect(settingsScreenRefusedValue($saved))->toBeFalse()
            ->and(json_decode((string) DB::table('platform.settings')->where('key', $key)->value('value'), true))->toBe(35);
    });

    // Loyalty's are the first on/off settings a module declares: saved here the way the page's
    // switch sends them, "true" and "false".
    it('saves an on/off setting as the switch sends it, and refuses anything else', function () {
        $browser = settingsScreenSignIn(Fx::staff(superAdmin: true));
        $key = ProgrammeSettings::ENABLED;
        $stored = fn (): mixed => json_decode((string) DB::table('platform.settings')->where('key', $key)->where('store_id', Fx::storeId('sa'))->value('value'), true);

        $on = $browser->post("/admin/settings/{$key}", ['value' => 'true', 'store' => 'sa'])->assertRedirect();
        expect(settingsScreenRefusedValue($on))->toBeFalse()
            ->and($stored())->toBeTrue();

        $maybe = $browser->post("/admin/settings/{$key}", ['value' => 'maybe', 'store' => 'sa'])->assertRedirect();
        $empty = $browser->post("/admin/settings/{$key}", ['value' => '', 'store' => 'sa'])->assertRedirect();
        expect(settingsScreenRefusedValue($maybe))->toBeTrue()
            ->and(settingsScreenRefusedValue($empty))->toBeTrue()
            ->and($stored())->toBeTrue();

        $off = $browser->post("/admin/settings/{$key}", ['value' => 'false', 'store' => 'sa'])->assertRedirect();
        expect(settingsScreenRefusedValue($off))->toBeFalse()
            ->and($stored())->toBeFalse();
    });

    it('lists the points programme under its own section, its switch as on/off', function () {
        $browser = settingsScreenSignIn(Fx::staff(superAdmin: true));

        $browser->get('/admin/settings?store=sa')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $inertia) {
                /** @var list<array{module: string, settings: list<array{key: string, type: string}>}> $groups */
                $groups = $inertia->toArray()['props']['groups'];
                $loyalty = array_values(array_filter($groups, static fn (array $group): bool => $group['module'] === 'loyalty'))[0] ?? null;
                $types = array_column($loyalty['settings'] ?? [], 'type', 'key');

                expect($loyalty)->not->toBeNull()
                    ->and($types[ProgrammeSettings::ENABLED] ?? null)->toBe('BOOLEAN')
                    ->and($types[ProgrammeSettings::MAX_REDEMPTION_PERCENT] ?? null)->toBe('INTEGER');
            });
    });

    it('refuses a global setting to somebody who does not reach every store', function () {
        $staffId = Fx::staffWith([AccessPermissions::STAFF_SETTINGS_UPDATE], ['sa'], RoleLevel::Admin);
        $browser = settingsScreenSignIn($staffId);

        $key = StaffSecuritySettings::LOCKOUT_MINUTES;
        $browser->post("/admin/settings/{$key}", ['value' => '30'])->assertRedirect();

        // Answered in the page, and nothing written: a global setting reaches every store, so
        // changing it needs the permission everywhere (Access amendment 5).
        expect(DB::table('platform.settings')->where('key', $key)->exists())->toBeFalse();
    });
});
