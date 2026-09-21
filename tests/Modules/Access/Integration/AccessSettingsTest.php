<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * Changes one setting through Platform's handler, as whoever is acting.
 */
function changeAccessSetting(string $key, ?string $storeCode, mixed $value): void
{
    app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, $storeCode, $value));
}

/**
 * The settings split (owner, 2026-09-21): the numbers that decide how staff sign in are an
 * admin-only action of their own; a store's own settings stay an ordinary one.
 */
describe('who may change which setting (spec §1.5, amendment 46)', function () {
    it('lets an admin holding the staff security action change how staff sign in', function () {
        Fx::actAsAdmin(['*'], [AccessPermissions::STAFF_SETTINGS_UPDATE]);

        changeAccessSetting(StaffSecuritySettings::PASSWORD_MIN_LENGTH, null, 16);

        expect(app(StaffSecuritySettings::class)->passwordMinLength())->toBe(16);
    });

    it('refuses the staff security settings to the store settings action', function () {
        // A store's own settings are not the rules of signing in: holding one says nothing about
        // the other, whichever stores it covers.
        Fx::actAsAdmin(['*'], [AccessPermissions::SETTINGS_UPDATE]);

        expect(fn () => changeAccessSetting(StaffSecuritySettings::PASSWORD_MIN_LENGTH, null, 16))
            ->toThrow(Unauthorized::class)
            ->and(app(StaffSecuritySettings::class)->passwordMinLength())->toBe(12);
    });

    it('lets an ordinary staff role change a store setting', function () {
        Fx::actAsStaff(Fx::staffWith([AccessPermissions::SETTINGS_UPDATE], ['sa']));

        Fx::inStoreCode('sa', fn () => changeAccessSetting(CustomerSecuritySettings::TERMS_VERSION, 'sa', '2026-09'));

        expect(Fx::inStoreCode('sa', fn (): string => app(CustomerSecuritySettings::class)->termsVersion()))->toBe('2026-09');
    });

    it('refuses a store setting to someone holding only the staff security action', function () {
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_SETTINGS_UPDATE]);

        expect(fn () => changeAccessSetting(CustomerSecuritySettings::TERMS_VERSION, 'sa', '2026-09'))
            ->toThrow(Unauthorized::class);
    });
});
