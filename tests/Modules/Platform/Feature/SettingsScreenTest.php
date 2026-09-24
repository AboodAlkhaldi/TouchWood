<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Domain\ValueObject\RoleLevel;
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
        $browser->post("/admin/settings/{$key}", ['value' => '20'])->assertRedirect();

        $row = DB::table('platform.settings')->where('key', $key)->first()
            ?? throw new RuntimeException('The setting was not stored.');

        // The store the panel is on, never one the form named.
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
