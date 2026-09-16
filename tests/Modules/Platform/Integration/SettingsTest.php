<?php

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Settings\InvalidSettingDefinition;
use Modules\Platform\Domain\Exception\InvalidSettingValue;
use Modules\Platform\Domain\Exception\SettingScopeMismatch;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Domain\Exception\UnknownSetting;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Events\SettingChanged;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

final class RecordingAuthorizer implements Authorizer
{
    /** @var list<array{string, string|null}> */
    public array $checks = [];

    public function authorize(string $permission, ?StoreId $store = null): void
    {
        $this->checks[] = [$permission, $store?->value];
    }
}

function defineTestSettings(): void
{
    app(SettingsRegistry::class)->define(
        'testing',
        new SettingDefinitionDto('testing.otp.max_resends', SettingScope::Store, ['integer', 'min:1', 'max:10'], 3, 'testing.settings.update'),
        new SettingDefinitionDto('testing.maintenance.enabled', SettingScope::Global, ['boolean'], false, 'testing.maintenance.update'),
    );
}

function storeIdFor(string $code): StoreId
{
    return app(PlatformApi::class)->storeByCode($code)?->storeId() ?? throw new LogicException("Store {$code} is not seeded.");
}

function setSetting(string $key, ?string $storeCode, mixed $value): void
{
    app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, $storeCode, $value));
}

beforeEach(function () {
    seed(PlatformSeeder::class);
    defineTestSettings();
});

describe('declaring', function () {
    it('refuses a key that does not follow module.area.name', function (string $key) {
        app(SettingsRegistry::class)->define('testing', new SettingDefinitionDto($key, SettingScope::Global, ['boolean'], false, 'testing.settings.update'));
    })->throws(InvalidSettingDefinition::class)->with(['testing.enabled', 'Testing.Otp.Max', 'testing..max', 'testing.otp.max-resends']);

    it('refuses a key that belongs to another module', function () {
        app(SettingsRegistry::class)->define('loyalty', new SettingDefinitionDto('testing.points.expiry_days', SettingScope::Store, ['integer'], 365, 'loyalty.settings.update'));
    })->throws(InvalidSettingDefinition::class, 'must start with "loyalty."');

    it('refuses a key declared twice', function () {
        defineTestSettings();
    })->throws(InvalidSettingDefinition::class, 'already declared');

    it('refuses a default that fails its own rules', function () {
        app(SettingsRegistry::class)->define('testing', new SettingDefinitionDto('testing.otp.length', SettingScope::Global, ['integer', 'min:4'], 2, 'testing.settings.update'));
    })->throws(InvalidSettingDefinition::class, 'fails its own rules');
});

describe('reading', function () {
    it('returns the default while nothing is stored', function () {
        $setting = app(PlatformApi::class)->setting('testing.otp.max_resends', storeIdFor('sa'));

        expect($setting->isDefault)->toBeTrue()
            ->and($setting->int())->toBe(3);
    });

    it('returns the stored value for that store only', function () {
        setSetting('testing.otp.max_resends', 'sa', 5);

        expect(app(PlatformApi::class)->setting('testing.otp.max_resends', storeIdFor('sa'))->int())->toBe(5)
            ->and(app(PlatformApi::class)->setting('testing.otp.max_resends', storeIdFor('eg'))->int())->toBe(3);
    });

    it('refuses a key no module declared', function () {
        app(PlatformApi::class)->setting('testing.nothing.here');
    })->throws(UnknownSetting::class);

    it('refuses to read a per-store setting without a store, or a global one with a store', function (string $key, bool $withStore) {
        app(PlatformApi::class)->setting($key, $withStore ? storeIdFor('sa') : null);
    })->throws(SettingScopeMismatch::class)->with([
        'per-store without a store' => ['testing.otp.max_resends', false],
        'global with a store' => ['testing.maintenance.enabled', true],
    ]);
});

describe('changing', function () {
    it('checks the permission from the definition, against the store', function () {
        $authorizer = new RecordingAuthorizer;
        app()->instance(Authorizer::class, $authorizer);

        setSetting('testing.otp.max_resends', 'ae', 4);
        setSetting('testing.maintenance.enabled', null, true);

        expect($authorizer->checks)->toBe([
            ['testing.settings.update', storeIdFor('ae')->value],
            // A global setting is checked with no store: it needs access to every store.
            ['testing.maintenance.update', null],
        ]);
    });

    it('serves the new value immediately', function () {
        expect(app(PlatformApi::class)->setting('testing.maintenance.enabled')->bool())->toBeFalse();

        setSetting('testing.maintenance.enabled', null, true);

        expect(app(PlatformApi::class)->setting('testing.maintenance.enabled')->bool())->toBeTrue();
    });

    it('keeps one row per key per store, and one global row per key', function () {
        setSetting('testing.otp.max_resends', 'sa', 4);
        setSetting('testing.otp.max_resends', 'sa', 6);
        setSetting('testing.maintenance.enabled', null, true);
        setSetting('testing.maintenance.enabled', null, false);

        expect(DB::table('platform.settings')->count())->toBe(2);
    });

    it('refuses a value that breaks the rules', function (mixed $value) {
        setSetting('testing.otp.max_resends', 'sa', $value);
    })->throws(InvalidSettingValue::class)->with([11, 0, 'many', null]);

    it('refuses the wrong scope and an unknown store', function (string $key, ?string $storeCode, string $error) {
        expect(fn () => setSetting($key, $storeCode, 1))->toThrow($error);
    })->with([
        'per-store without a store' => ['testing.otp.max_resends', null, SettingScopeMismatch::class],
        'global with a store' => ['testing.maintenance.enabled', 'sa', SettingScopeMismatch::class],
        'unknown store' => ['testing.otp.max_resends', 'zz', StoreNotFound::class],
        'undeclared key' => ['testing.nothing.here', null, UnknownSetting::class],
    ]);

    it('audits the change with the old and new value, and announces it', function () {
        Event::fake([SettingChanged::class]);

        setSetting('testing.otp.max_resends', 'eg', 7);

        $entry = (array) DB::table('platform.audit_entries')->where('action', 'platform.setting.updated')->first();
        $changes = json_decode((string) $entry['changes'], true);

        expect($entry['store_id'])->toBe(storeIdFor('eg')->value)
            ->and($changes)->toEqual(['key' => ['testing.otp.max_resends', 'testing.otp.max_resends'], 'value' => [3, 7]]);
        Event::assertDispatched(SettingChanged::class, fn (SettingChanged $event): bool => $event->key === 'testing.otp.max_resends' && $event->storeId === storeIdFor('eg')->value);
    });

    it('writes and audits nothing when the value is unchanged', function () {
        setSetting('testing.otp.max_resends', 'sa', 5);
        $auditsBefore = DB::table('platform.audit_entries')->count();

        setSetting('testing.otp.max_resends', 'sa', 5);

        expect(DB::table('platform.audit_entries')->count())->toBe($auditsBefore);
    });
});

it('refuses a duplicate global row at the database', function () {
    $row = ['store_id' => null, 'key' => 'testing.maintenance.enabled', 'value' => 'true', 'updated_at' => now()];
    DB::table('platform.settings')->insert($row);

    expect(fn () => DB::table('platform.settings')->insert($row))->toThrow(QueryException::class);
});
