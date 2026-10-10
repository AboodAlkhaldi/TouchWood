<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Domain\Exception\InvalidSettingValue;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| Each store's points programme (loyalty.md §1.5): eight Platform settings declared by Loyalty, one
| set per store, changed only under `loyalty.settings.update` — which only an admin role may hold —
| in that store. The programme starts off everywhere (owner, 2026-10-07).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** Named for this file: a function declared in a Pest file is global to the whole suite. */
function loyaltySettingStore(string $code): StoreId
{
    return app(PlatformApi::class)->storeByCode($code)?->storeId() ?? throw new LogicException("Store {$code} is not seeded.");
}

function loyaltySettingSave(string $key, string $storeCode, mixed $value): void
{
    app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, $storeCode, $value));
}

/**
 * Each setting: its type, the default, and the smallest and largest value it takes (null for a
 * Boolean). The spec's table, word for word.
 *
 * @return array<string, array{0: string, 1: SettingType, 2: int|bool, 3: int|null, 4: int|null}>
 */
function loyaltySettingsTable(): array
{
    return [
        'enabled' => [ProgrammeSettings::ENABLED, SettingType::Boolean, false, null, null],
        'earn per unit' => [ProgrammeSettings::EARN_PER_UNIT, SettingType::Integer, 1, 1, 1_000],
        'points per unit off' => [ProgrammeSettings::POINTS_PER_UNIT_OFF, SettingType::Integer, 100, 1, 100_000],
        'expiry months' => [ProgrammeSettings::EXPIRY_MONTHS, SettingType::Integer, 12, 1, 120],
        'minimum redemption' => [ProgrammeSettings::MINIMUM_REDEMPTION, SettingType::Integer, 0, 0, 1_000_000],
        'max redemption percent' => [ProgrammeSettings::MAX_REDEMPTION_PERCENT, SettingType::Integer, 50, 0, 100],
        'individuals partial' => [ProgrammeSettings::PUBLIC_PARTIAL, SettingType::Boolean, true, null, null],
        'companies partial' => [ProgrammeSettings::COMPANY_PARTIAL, SettingType::Boolean, true, null, null],
    ];
}

it('declares exactly the eight settings of the spec, in Loyalty\'s keys', function () {
    $keys = array_map(static fn (array $row): string => $row[0], array_values(loyaltySettingsTable()));
    // Read from Platform's registry, so a loyalty setting declared anywhere else is seen too.
    $declared = array_values(array_filter(
        array_map(static fn (SettingDefinitionDto $definition): string => $definition->key, app(InMemorySettingsRegistry::class)->all()),
        static fn (string $key): bool => str_starts_with($key, 'loyalty.'),
    ));

    expect($declared)->toBe($keys)
        ->and($keys)->toBe([
            'loyalty.points.enabled',
            'loyalty.points.earn_per_unit',
            'loyalty.points.points_per_unit_off',
            'loyalty.points.expiry_months',
            'loyalty.points.minimum_redemption',
            'loyalty.points.max_redemption_percent',
            'loyalty.redemption.public_partial',
            'loyalty.redemption.company_partial',
        ]);
});

it('makes each one per store, of its type, with its default, changed under the programme\'s job', function (string $key, SettingType $type, int|bool $default) {
    $definition = app(SettingsRegistry::class)->definition($key);

    expect($definition)->not->toBeNull()
        ->and($definition?->scope)->toBe(SettingScope::Store)
        ->and($definition?->type)->toBe($type)
        ->and($definition?->default)->toBe($default)
        ->and($definition?->permission)->toBe(LoyaltyPermissions::SETTINGS_UPDATE)
        ->and($definition?->sensitive)->toBeFalse();

    $read = app(PlatformApi::class)->setting($key, loyaltySettingStore('sa'));

    expect($type === SettingType::Boolean ? $read->bool() : $read->int())->toBe($default);
})->with(loyaltySettingsTable());

it('starts the programme off in every store', function () {
    expect(app(PlatformApi::class)->allStores())->not->toBeEmpty();

    foreach (app(PlatformApi::class)->allStores() as $store) {
        expect(app(PlatformApi::class)->setting(ProgrammeSettings::ENABLED, $store->storeId())->bool())->toBeFalse();
    }
});

it('takes each number at its bounds and refuses one past them, leaving the value as it was', function (string $key, SettingType $type, int|bool $default, ?int $min, ?int $max) {
    if ($type === SettingType::Boolean) {
        loyaltySettingSave($key, 'sa', ! $default);

        expect(app(PlatformApi::class)->setting($key, loyaltySettingStore('sa'))->bool())->toBe(! $default);

        return;
    }

    loyaltySettingSave($key, 'sa', $min);
    loyaltySettingSave($key, 'sa', $max);

    expect(fn () => loyaltySettingSave($key, 'sa', $min - 1))->toThrow(InvalidSettingValue::class)
        ->and(fn () => loyaltySettingSave($key, 'sa', $max + 1))->toThrow(InvalidSettingValue::class)
        ->and(fn () => loyaltySettingSave($key, 'sa', '12'))->toThrow(InvalidSettingValue::class)
        ->and(app(PlatformApi::class)->setting($key, loyaltySettingStore('sa'))->int())->toBe($max);
})->with(loyaltySettingsTable());

it('keeps one store\'s programme apart from another\'s', function () {
    loyaltySettingSave(ProgrammeSettings::ENABLED, 'sa', true);
    loyaltySettingSave(ProgrammeSettings::EXPIRY_MONTHS, 'sa', 6);

    expect(app(PlatformApi::class)->setting(ProgrammeSettings::ENABLED, loyaltySettingStore('sa'))->bool())->toBeTrue()
        ->and(app(PlatformApi::class)->setting(ProgrammeSettings::EXPIRY_MONTHS, loyaltySettingStore('sa'))->int())->toBe(6)
        ->and(app(PlatformApi::class)->setting(ProgrammeSettings::ENABLED, loyaltySettingStore('eg'))->bool())->toBeFalse()
        ->and(app(PlatformApi::class)->setting(ProgrammeSettings::EXPIRY_MONTHS, loyaltySettingStore('eg'))->int())->toBe(12);
});

it('lets an admin holding the job change the programme of their own store, and no other', function () {
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin));

    loyaltySettingSave(ProgrammeSettings::ENABLED, 'sa', true);

    expect(app(PlatformApi::class)->setting(ProgrammeSettings::ENABLED, loyaltySettingStore('sa'))->bool())->toBeTrue()
        ->and(fn () => loyaltySettingSave(ProgrammeSettings::ENABLED, 'eg', true))->toThrow(Unauthorized::class)
        ->and(app(PlatformApi::class)->setting(ProgrammeSettings::ENABLED, loyaltySettingStore('eg'))->bool())->toBeFalse();
});

it('refuses the programme to a staff member without the job, though they view the store\'s points', function () {
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    expect(fn () => loyaltySettingSave(ProgrammeSettings::ENABLED, 'sa', true))->toThrow(Unauthorized::class)
        ->and(app(PlatformApi::class)->setting(ProgrammeSettings::ENABLED, loyaltySettingStore('sa'))->bool())->toBeFalse();
});

it('names the section and every setting in both languages', function () {
    // hasForLocale: trans() would fall back to English for a missing Arabic name.
    foreach (['ar', 'en'] as $locale) {
        expect(Lang::hasForLocale('loyalty::settings.module', $locale))->toBeTrue("no {$locale} name for the section");

        foreach (ProgrammeSettings::definitions() as $definition) {
            expect(Lang::hasForLocale($definition->labelKey(), $locale))->toBeTrue("{$definition->key} has no {$locale} name");
        }
    }

    expect(trans('loyalty::settings.module', [], 'en'))->toBe('Points')
        ->and(trans('loyalty::settings.module', [], 'ar'))->toBe('النقاط');
});
