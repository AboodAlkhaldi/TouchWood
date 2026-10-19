<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Domain\Exception\InvalidSettingValue;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Sales's store settings (sales.md §1.10): the flat shipping fee and free shipping from — 0 and 0,
| never free — and the return window, 14 days; integers in their ranges; admin-only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

it('declares the three settings of the spec, per store, with their defaults', function () {
    $declared = array_values(array_filter(
        array_map(static fn (SettingDefinitionDto $definition): string => $definition->key, app(InMemorySettingsRegistry::class)->all()),
        static fn (string $key): bool => str_starts_with($key, 'sales.'),
    ));

    expect($declared)->toBe([SalesSettings::FLAT_FEE, SalesSettings::FREE_ABOVE, SalesSettings::RETURN_WINDOW_DAYS]);

    foreach ([SalesSettings::FLAT_FEE => 0, SalesSettings::FREE_ABOVE => 0, SalesSettings::RETURN_WINDOW_DAYS => 14] as $key => $default) {
        $definition = app(SettingsRegistry::class)->definition($key);

        expect([$definition?->scope, $definition?->type, $definition?->permission, $definition?->sensitive])->toBe([SettingScope::Store, SettingType::Integer, SalesPermissions::SETTINGS_UPDATE, false])
            ->and(app(PlatformApi::class)->setting($key, StoreId::fromString(Fx::storeId('sa')))->int())->toBe($default);
    }
});

it('takes each value within its range and refuses one outside it', function (string $key, int $lowest, int $highest) {
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin));
    $set = static fn (int $value) => app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, 'sa', $value));

    $set($lowest);
    $set($highest);

    expect(app(PlatformApi::class)->setting($key, StoreId::fromString(Fx::storeId('sa')))->int())->toBe($highest)
        ->and(fn () => $set($lowest - 1))->toThrow(InvalidSettingValue::class)
        ->and(fn () => $set($highest + 1))->toThrow(InvalidSettingValue::class);
})->with([
    'the fee' => [SalesSettings::FLAT_FEE, 0, 100_000_000],
    'free from' => [SalesSettings::FREE_ABOVE, 0, 1_000_000_000],
    'the window' => [SalesSettings::RETURN_WINDOW_DAYS, 1, 365],
]);

it('refuses them to staff without the admin-only job', function () {
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));

    expect(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(SalesSettings::FLAT_FEE, 'sa', 25_00)))->toThrow(Unauthorized::class);
});

it('names the section and each setting in both languages', function () {
    foreach (['ar', 'en'] as $locale) {
        expect(Lang::hasForLocale('sales::settings.module', $locale))->toBeTrue("no {$locale} name for the section");

        foreach (SalesSettings::definitions() as $definition) {
            expect(Lang::hasForLocale($definition->labelKey(), $locale))->toBeTrue("{$definition->key} has no {$locale} name");
        }
    }
});
