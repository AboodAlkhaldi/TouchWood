<?php

declare(strict_types=1);

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
use Modules\Platform\Public\Enums\SettingType;
use Modules\Platform\Public\Events\SettingChanged;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

final class RecordingAuthorizer implements Authorizer
{
    /** @var list<array{string, string}> */
    public array $checks = [];

    public function authorize(string $permission, PermissionScope $scope): void
    {
        $this->checks[] = [$permission, $scope->describe()];
    }

    public function storesWith(string $permission): ?array
    {
        return null;
    }
}

function defineTestSettings(): void
{
    app(SettingsRegistry::class)->define(
        'testing',
        new SettingDefinitionDto('testing.otp.max_resends', SettingScope::Store, SettingType::Integer, ['min:1', 'max:10'], 3, 'testing.settings.update'),
        new SettingDefinitionDto('testing.maintenance.enabled', SettingScope::Global, SettingType::Boolean, [], false, 'testing.maintenance.update'),
        new SettingDefinitionDto('testing.sms.sender_name', SettingScope::Global, SettingType::Text, ['max:11'], 'TouchWood', 'testing.settings.update'),
        new SettingDefinitionDto('testing.bank.iban', SettingScope::Store, SettingType::Text, [], 'SA00', 'testing.settings.update', sensitive: true),
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
        app(SettingsRegistry::class)->define('testing', new SettingDefinitionDto($key, SettingScope::Global, SettingType::Boolean, [], false, 'testing.settings.update'));
    })->throws(InvalidSettingDefinition::class)->with(['testing.enabled', 'Testing.Otp.Max', 'testing..max', 'testing.otp.max-resends', "testing.otp.max\n"]);

    it('refuses a setting that looks like a secret: secrets live in server environment variables', function (string $key) {
        app(SettingsRegistry::class)->define('testing', new SettingDefinitionDto($key, SettingScope::Global, SettingType::Text, [], 'x', 'testing.settings.update'));
    })->throws(InvalidSettingDefinition::class, 'looks like a secret')->with([
        'testing.sms.api_key', 'testing.gateway.apikey', 'testing.odoo.password', 'testing.bank.client_secret',
        'testing.storage.access_key', 'testing.signing.private_key', 'testing.odoo.credentials',
        'testing.odoo.access_token', 'testing.sms.api_token', 'testing.webhook.signing_key',
        'testing.webhook.hmac_key', 'testing.mail.smtp_pass', 'testing.files.encryption_key',
        // A secret in any segment, not only the last.
        'testing.api_key.live',
    ]);

    it('does not mistake an ordinary name for a secret', function (string $key) {
        app(SettingsRegistry::class)->define('testing', new SettingDefinitionDto($key, SettingScope::Global, SettingType::Integer, [], 6, 'testing.settings.update'));

        expect(app(PlatformApi::class)->setting($key)->int())->toBe(6);
    })->with([
        'testing.otp.token_length', 'testing.keys.per_page', 'testing.secretary.count',
        // A policy about a secret is not a secret.
        'testing.accounts.password_min_length', 'testing.accounts.password_expiry_days',
    ]);

    it('refuses a key that belongs to another module', function () {
        app(SettingsRegistry::class)->define('loyalty', new SettingDefinitionDto('testing.points.expiry_days', SettingScope::Store, SettingType::Integer, [], 365, 'loyalty.settings.update'));
    })->throws(InvalidSettingDefinition::class, 'must start with "loyalty."');

    it('refuses a key declared twice', function () {
        defineTestSettings();
    })->throws(InvalidSettingDefinition::class, 'already declared');

    it('refuses a default that fails its own rules', function () {
        app(SettingsRegistry::class)->define('testing', new SettingDefinitionDto('testing.otp.length', SettingScope::Global, SettingType::Integer, ['min:4'], 2, 'testing.settings.update'));
    })->throws(InvalidSettingDefinition::class, 'fails its own type or rules');

    it('refuses a default of the wrong type', function (SettingType $type, mixed $default) {
        app(SettingsRegistry::class)->define('testing', new SettingDefinitionDto('testing.otp.length', SettingScope::Global, $type, [], $default, 'testing.settings.update'));
    })->throws(InvalidSettingDefinition::class, 'fails its own type or rules')->with([
        'numeric text as an integer' => [SettingType::Integer, '6'],
        'one as a boolean' => [SettingType::Boolean, 1],
        'empty text' => [SettingType::Text, ''],
        'a map as a list' => [SettingType::List, ['a' => 1]],
    ]);
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
            // A global setting reaches every store, so it needs the permission in all of them.
            ['testing.maintenance.update', 'all stores'],
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

    it('refuses a value of the wrong type that Laravel rules alone would let through', function (string $key, ?string $store, mixed $value) {
        setSetting($key, $store, $value);
    })->throws(InvalidSettingValue::class)->with([
        // Laravel skips non-implicit rules for an empty string.
        'empty string as an integer' => ['testing.otp.max_resends', 'sa', ''],
        'whitespace as an integer' => ['testing.otp.max_resends', 'sa', '   '],
        // Non-strict "integer" and "boolean" accept these.
        'numeric text as an integer' => ['testing.otp.max_resends', 'sa', '5'],
        'one as a boolean' => ['testing.maintenance.enabled', null, 1],
        'empty text' => ['testing.sms.sender_name', null, ''],
    ]);

    it('can always read back a value it accepted', function () {
        setSetting('testing.otp.max_resends', 'sa', 7);
        setSetting('testing.maintenance.enabled', null, true);
        setSetting('testing.sms.sender_name', null, 'TW Store');

        expect(app(PlatformApi::class)->setting('testing.otp.max_resends', storeIdFor('sa'))->int())->toBe(7)
            ->and(app(PlatformApi::class)->setting('testing.maintenance.enabled')->bool())->toBeTrue()
            ->and(app(PlatformApi::class)->setting('testing.sms.sender_name')->string())->toBe('TW Store');
    });

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

    it('records only that a sensitive setting changed, never its values', function () {
        setSetting('testing.bank.iban', 'sa', 'SA0380000000608010167519');

        $entry = (array) DB::table('platform.audit_entries')->where('action', 'platform.setting.updated')->first();

        expect(json_decode((string) $entry['changes'], true))->toBe(['key' => ['testing.bank.iban', 'testing.bank.iban'], 'value' => 'changed'])
            ->and((string) $entry['changes'])->not->toContain('SA03')
            ->and(app(PlatformApi::class)->setting('testing.bank.iban', storeIdFor('sa'))->string())->toBe('SA0380000000608010167519');
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
