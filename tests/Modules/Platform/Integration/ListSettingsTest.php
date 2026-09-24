<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Platform\Application\Query\ListSettings\ListSettings;
use Modules\Platform\Application\Query\ListSettings\ListSettingsHandler;
use Modules\Platform\Application\Query\ListSettings\SettingRow;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * @return list<string>
 */
function listedSettingKeys(?string $storeId = null): array
{
    return array_map(
        static fn (SettingRow $row): string => $row->key,
        app(ListSettingsHandler::class)->handle(new ListSettings($storeId)),
    );
}

/**
 * Stage 2b, step 3. Which settings a person is shown (frontend.md §3.5, E4).
 *
 * "A row a person may not change is not shown to them." Each setting carries its own permission,
 * declared with it by the module that owns it, so this is one answer per row rather than one for
 * the screen — and the scope decides where that permission has to hold.
 */
describe('the settings a person is shown', function () {
    it('shows a Super Admin everything that is declared', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        // Every module's, in one list; the screen is what groups them.
        expect(listedSettingKeys(Fx::storeId('sa')))
            ->toContain(StaffSecuritySettings::LOCKOUT_MINUTES)
            ->toContain(CustomerSecuritySettings::LOCKOUT_MINUTES)
            ->toContain('platform.media.max_public_bytes');
    });

    it('keeps a global setting from somebody who does not reach every store', function () {
        // A global setting is one value for the whole system, so holding the permission in some
        // stores is not holding it (Access amendment 5).
        Fx::actAsAdmin(['sa'], [AccessPermissions::STAFF_SETTINGS_UPDATE, PlatformPermissions::SETTINGS_UPDATE]);

        $keys = listedSettingKeys(Fx::storeId('sa'));

        expect($keys)->not->toContain(StaffSecuritySettings::LOCKOUT_MINUTES)
            ->and($keys)->not->toContain('platform.media.max_public_bytes');
    });

    it('shows a store setting to somebody who holds it in the store the panel is on', function () {
        Fx::actAsAdmin(['sa'], [AccessPermissions::SETTINGS_UPDATE]);

        expect(listedSettingKeys(Fx::storeId('sa')))->toContain(CustomerSecuritySettings::LOCKOUT_MINUTES);
    });

    it('keeps a store setting from somebody who holds it somewhere else', function () {
        // They may change Cairo's numbers; the panel is on Riyadh, so this screen offers nothing.
        Fx::actAsAdmin(['eg'], [AccessPermissions::SETTINGS_UPDATE]);

        expect(listedSettingKeys(Fx::storeId('sa')))->not->toContain(CustomerSecuritySettings::LOCKOUT_MINUTES);
    });

    it('offers no store setting at all when the panel is on no store', function () {
        Fx::actAsAdmin(['sa'], [AccessPermissions::SETTINGS_UPDATE]);

        expect(listedSettingKeys(null))->toBe([]);
    });

    it('carries the bounds the module declared, so the screen cannot offer a refused number', function () {
        Fx::actAsStaff(Fx::staff(superAdmin: true));

        $rows = app(ListSettingsHandler::class)->handle(new ListSettings(Fx::storeId('sa')));
        $row = collect($rows)->firstWhere('key', StaffSecuritySettings::LOCKOUT_ATTEMPTS)
            ?? throw new RuntimeException('The lockout setting is not declared.');

        // Read from the setting's own rules rather than written out twice.
        expect($row->min)->toBe(3)
            ->and($row->max)->toBe(20);
    });
});
