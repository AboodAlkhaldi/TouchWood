<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\FeedbackSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
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
| Feedback's one setting (feedback.md §1.2): stars and the rating sort in a store's lists — a Platform
| setting per store, on by default, changed only under the admin-only `feedback.settings.update` in
| that store (owner, 2026-10-09).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function feedbackSettingStore(string $code): StoreId
{
    return app(PlatformApi::class)->storeByCode($code)?->storeId() ?? throw new LogicException("Store {$code} is not seeded.");
}

it('declares exactly the one setting of the spec: per store, a switch, on, changed under the admin-only job', function () {
    $declared = array_values(array_filter(
        array_map(static fn (SettingDefinitionDto $definition): string => $definition->key, app(InMemorySettingsRegistry::class)->all()),
        static fn (string $key): bool => str_starts_with($key, 'feedback.'),
    ));
    $definition = app(SettingsRegistry::class)->definition(FeedbackSettings::RATINGS_IN_LISTS);

    expect($declared)->toBe(['feedback.ratings.in_lists'])
        ->and($definition?->scope)->toBe(SettingScope::Store)
        ->and($definition?->type)->toBe(SettingType::Boolean)
        ->and($definition?->default)->toBeTrue()
        ->and($definition?->permission)->toBe(FeedbackPermissions::SETTINGS_UPDATE)
        ->and($definition?->sensitive)->toBeFalse();

    foreach (app(PlatformApi::class)->allStores() as $store) {
        expect(app(PlatformApi::class)->setting(FeedbackSettings::RATINGS_IN_LISTS, $store->storeId())->bool())->toBeTrue();
    }
});

it('lets an admin holding the job switch it in their own store only, and keeps stores apart', function () {
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::SETTINGS_UPDATE], ['sa'], RoleLevel::Admin));

    app(UpdateSettingHandler::class)->handle(new UpdateSetting(FeedbackSettings::RATINGS_IN_LISTS, 'sa', false));

    expect(app(PlatformApi::class)->setting(FeedbackSettings::RATINGS_IN_LISTS, feedbackSettingStore('sa'))->bool())->toBeFalse()
        ->and(app(PlatformApi::class)->setting(FeedbackSettings::RATINGS_IN_LISTS, feedbackSettingStore('eg'))->bool())->toBeTrue()
        ->and(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(FeedbackSettings::RATINGS_IN_LISTS, 'eg', false)))->toThrow(Unauthorized::class);
});

it('refuses it to staff without the job, though they moderate the store\'s reviews', function () {
    Fx::actAsStaff(Fx::staffWith([FeedbackPermissions::REVIEW_MODERATE], ['sa']));

    expect(fn () => app(UpdateSettingHandler::class)->handle(new UpdateSetting(FeedbackSettings::RATINGS_IN_LISTS, 'sa', false)))->toThrow(Unauthorized::class);
});

it('names the section and the setting in both languages', function () {
    foreach (['ar', 'en'] as $locale) {
        expect(Lang::hasForLocale('feedback::settings.module', $locale))->toBeTrue("no {$locale} name for the section");

        foreach (FeedbackSettings::definitions() as $definition) {
            expect(Lang::hasForLocale($definition->labelKey(), $locale))->toBeTrue("{$definition->key} has no {$locale} name");
        }
    }
});
