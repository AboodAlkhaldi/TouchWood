<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Infrastructure\Permission\PermissionSync;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Domain\Exception\AdminOnlyPermission;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Payments\Application\Settings\BankAccountSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Settings\InMemorySettingsSectionLines;
use Modules\Platform\Application\Settings\SettingValues;
use Modules\Platform\Domain\Exception\InvalidSettingValue;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Contracts\SettingsSectionLines;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The bank account an approved company transfers to (payments.md §1.9, b2b.md amendment 33): three Platform
| settings per store, declared by Payments, changed only by an admin under `payments.bank_account.update`.
| They start empty, meaning not set yet.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/** Named for this file: a function declared in a Pest file is global to the whole suite. */
function bankSettingStore(string $code): StoreId
{
    return app(PlatformApi::class)->storeByCode($code)?->storeId() ?? throw new LogicException("Store {$code} is not seeded.");
}

function bankSettingSave(string $key, string $storeCode, string $value): void
{
    app(UpdateSettingHandler::class)->handle(new UpdateSetting($key, $storeCode, $value));
}

function bankSettingRead(string $key, string $storeCode): string
{
    return app(PlatformApi::class)->setting($key, bankSettingStore($storeCode))->string();
}

it('declares the IBAN, the bank and the holder: per store, text, empty until set, under an admin-only job (amendment 33)', function () {
    $keys = [BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER];

    foreach ($keys as $key) {
        $definition = app(SettingsRegistry::class)->definition($key);

        expect($definition)->not->toBeNull()
            ->and($definition?->scope)->toBe(SettingScope::Store)
            ->and($definition?->type)->toBe(SettingType::Text)
            ->and($definition?->default)->toBe('')
            ->and($definition?->mayBeEmpty)->toBeTrue()
            ->and($definition?->permission)->toBe(PaymentsPermissions::BANK_ACCOUNT_UPDATE)
            // Shown to every approved company of the store: nothing about it is secret.
            ->and($definition?->sensitive)->toBeFalse()
            ->and(bankSettingRead($key, 'sa'))->toBe('');
    }

    expect($keys)->toBe(['payments.bank.iban', 'payments.bank.name', 'payments.bank.holder']);
});

// Security audit run-2 (2026-10-27): any staff role holding the store settings job could change where
// approved companies send their money.
it('refuses the bank account to a staff role holding the store settings job', function (string $key) {
    Fx::actAsStaff(Fx::staffWith([PlatformPermissions::SETTINGS_UPDATE], ['sa']));

    expect(fn () => bankSettingSave($key, 'sa', $key === BankAccountSettings::IBAN ? 'GB82 WEST 1234 5698 7654 32' : 'Al Noor Bank'))->toThrow(Unauthorized::class)
        ->and(bankSettingRead($key, 'sa'))->toBe('');
})->with([BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER]);

it('lets an admin holding the job change the bank account of their own store only', function () {
    Fx::actAsStaff(Fx::staffWith([PaymentsPermissions::BANK_ACCOUNT_UPDATE], ['sa'], RoleLevel::Admin));

    bankSettingSave(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32');

    expect(bankSettingRead(BankAccountSettings::IBAN, 'sa'))->toBe('GB82 WEST 1234 5698 7654 32')
        ->and(fn () => bankSettingSave(BankAccountSettings::IBAN, 'eg', 'GB82 WEST 1234 5698 7654 32'))->toThrow(Unauthorized::class)
        ->and(bankSettingRead(BankAccountSettings::IBAN, 'eg'))->toBe('');
});

it('keeps an IBAN as it was typed, and a store\'s bank is its own', function () {
    bankSettingSave(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32');

    expect(bankSettingRead(BankAccountSettings::IBAN, 'sa'))->toBe('GB82 WEST 1234 5698 7654 32')
        ->and(bankSettingRead(BankAccountSettings::IBAN, 'eg'))->toBe('');
});

it('refuses an IBAN whose check digits are wrong, and takes the empty text back', function () {
    bankSettingSave(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32');

    expect(fn () => bankSettingSave(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 33'))->toThrow(InvalidSettingValue::class)
        ->and(bankSettingRead(BankAccountSettings::IBAN, 'sa'))->toBe('GB82 WEST 1234 5698 7654 32');

    bankSettingSave(BankAccountSettings::IBAN, 'sa', '');

    expect(bankSettingRead(BankAccountSettings::IBAN, 'sa'))->toBe('');
});

it('takes the longest IBAN written in groups of four, and nothing longer', function () {
    // 34 characters and the 8 spaces between their groups: 42.
    $grouped = trim(chunk_split('ZZ64AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 4, ' '));

    bankSettingSave(BankAccountSettings::IBAN, 'sa', $grouped);

    expect(strlen($grouped))->toBe(42)
        ->and(bankSettingRead(BankAccountSettings::IBAN, 'sa'))->toBe($grouped)
        ->and(fn () => bankSettingSave(BankAccountSettings::IBAN, 'sa', $grouped.' '))->toThrow(InvalidSettingValue::class);
});

it('takes a bank and a holder of one line, up to 100 characters', function (string $key) {
    bankSettingSave($key, 'sa', str_repeat('ب', 100));

    expect(bankSettingRead($key, 'sa'))->toBe(str_repeat('ب', 100))
        ->and(fn () => bankSettingSave($key, 'sa', str_repeat('ب', 101)))->toThrow(InvalidSettingValue::class)
        ->and(fn () => bankSettingSave($key, 'sa', "Al Noor\nBank"))->toThrow(InvalidSettingValue::class)
        ->and(fn () => bankSettingSave($key, 'sa', "Al Noor\tBank"))->toThrow(InvalidSettingValue::class)
        ->and(fn () => bankSettingSave($key, 'sa', '   '))->toThrow(InvalidSettingValue::class);
})->with([BankAccountSettings::BANK, BankAccountSettings::HOLDER]);

it('names each section and every one of its settings on the settings screen, in Arabic and English', function (string $module, int $count) {
    foreach (['ar', 'en'] as $locale) {
        $keys = [$module.'::settings.module'];

        foreach (app(SettingsRegistry::class)->all() as $definition) {
            if (str_starts_with($definition->key, $module.'.')) {
                $keys[] = $definition->labelKey();
            }
        }

        expect($keys)->toHaveCount($count);

        foreach ($keys as $key) {
            $name = trans($key, [], $locale);

            expect(is_string($name) && $name !== '' && $name !== $key)->toBeTrue("{$key} has no {$locale} name");
        }
    }
})->with([
    'Payments: the timers, the check hours and the bank account\'s three' => ['payments', 8],
    'Companies: the form\'s five minimums (b2b.md amendment 16(b))' => ['b2b', 6],
]);

it('gives the settings section no line on a page showing no store: the account is per store (amendment 13(c))', function () {
    bankSettingSave(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32');
    bankSettingSave(BankAccountSettings::BANK, 'sa', 'Al Noor Bank');
    bankSettingSave(BankAccountSettings::HOLDER, 'sa', 'TouchWood Trading');
    // The registry behind Platform's contract, where Payments registered its line at boot; read in English
    // here, as the panel's language decides it there.
    app()->setLocale('en');
    $line = app(InMemorySettingsSectionLines::class)->for('payments');

    expect(app(SettingsSectionLines::class))->toBe(app(InMemorySettingsSectionLines::class))
        ->and(app(InMemorySettingsSectionLines::class)->for('b2b'))->toBeNull()
        ->and($line?->line(null))->toBeNull()
        ->and($line?->line(bankSettingStore('sa')))->toEndWith(' · Bank transfer: on')
        ->and($line?->line(bankSettingStore('eg')))->toEndWith(' · Bank transfer: temporarily off — fill in all three to turn it on.');
});

it('keeps the job per store, in the Payments group, and for admin roles only (b2b.md amendments 32, 33)', function () {
    $catalog = app(InMemoryPermissionCatalog::class);
    $permission = $catalog->definition(PaymentsPermissions::BANK_ACCOUNT_UPDATE);

    expect($permission?->kind)->toBe(PermissionKind::PerStore)
        ->and($permission?->group)->toBe(PermissionGroup::Payments)
        ->and($permission?->reserved)->toBeFalse()
        ->and($catalog->isAdminOnly(PaymentsPermissions::BANK_ACCOUNT_UPDATE))->toBeTrue()
        ->and(Fx::rolePermissions(Fx::role([PaymentsPermissions::BANK_ACCOUNT_UPDATE], RoleLevel::Admin)))->toBe([PaymentsPermissions::BANK_ACCOUNT_UPDATE])
        ->and(fn () => Fx::role([PaymentsPermissions::BANK_ACCOUNT_UPDATE], RoleLevel::Staff))->toThrow(AdminOnlyPermission::class);
});

describe('the bank account a store\'s companies transfer to (b2b.md amendment 13(c))', function () {
    it('gives the store\'s own account once all three are filled in', function () {
        foreach ([['sa', 'GB82 WEST 1234 5698 7654 32', 'Al Noor Bank', 'TouchWood Trading'], ['eg', 'DE89 3704 0044 0532 0130 00', 'Another Bank', 'Another Holder']] as [$code, $iban, $bank, $holder]) {
            bankSettingSave(BankAccountSettings::IBAN, $code, $iban);
            bankSettingSave(BankAccountSettings::BANK, $code, $bank);
            bankSettingSave(BankAccountSettings::HOLDER, $code, $holder);
        }

        $account = app(PaymentsApi::class)->bankAccount(bankSettingStore('sa'));

        expect([$account?->iban, $account?->bank, $account?->holder])->toBe(['GB82 WEST 1234 5698 7654 32', 'Al Noor Bank', 'TouchWood Trading']);
    });

    it('gives none while any one of the three is empty: bank transfer is temporarily off', function (string $empty) {
        bankSettingSave(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32');
        bankSettingSave(BankAccountSettings::BANK, 'sa', 'Al Noor Bank');
        bankSettingSave(BankAccountSettings::HOLDER, 'sa', 'TouchWood Trading');
        bankSettingSave($empty, 'sa', '');

        expect(app(PaymentsApi::class)->bankAccount(bankSettingStore('sa')))->toBeNull();
    })->with([BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER]);

    it('gives none for a store that has entered nothing', function () {
        bankSettingSave(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32');
        bankSettingSave(BankAccountSettings::BANK, 'sa', 'Al Noor Bank');
        bankSettingSave(BankAccountSettings::HOLDER, 'sa', 'TouchWood Trading');

        expect(app(PaymentsApi::class)->bankAccount(bankSettingStore('eg')))->toBeNull();
    });
});

describe('the move from B2B (b2b.md amendment 33)', function () {
    /** The move itself, run again on rows written as B2B kept them. */
    function bankMove(string $direction): void
    {
        $migration = require base_path('src/Modules/Payments/Infrastructure/Persistence/Migrations/2026_11_01_100000_move_bank_account_settings_from_b2b.php');
        $migration->{$direction}();
        app(SettingValues::class)->invalidate();
    }

    /** @param array<string, string> $values key => value */
    function bankOldRows(string $storeCode, array $values, string $staffId): void
    {
        foreach ($values as $key => $value) {
            DB::table('platform.settings')->insert(['store_id' => Fx::storeId($storeCode), 'key' => $key, 'value' => json_encode($value), 'updated_by' => $staffId, 'updated_at' => '2026-10-01 10:00:00+00']);
        }
    }

    it('keeps what each store saved, and who saved it and when, under the new names', function () {
        $staffId = Fx::staff(superAdmin: true);
        bankOldRows('sa', ['b2b.bank.iban' => 'GB82 WEST 1234 5698 7654 32', 'b2b.bank.name' => 'Al Noor Bank', 'b2b.bank.holder' => 'TouchWood Trading'], $staffId);
        bankOldRows('eg', ['b2b.bank.iban' => 'DE89 3704 0044 0532 0130 00'], $staffId);

        bankMove('up');

        $account = app(PaymentsApi::class)->bankAccount(bankSettingStore('sa'));
        $row = DB::table('platform.settings')->where('store_id', Fx::storeId('sa'))->where('key', BankAccountSettings::IBAN)->sole();

        expect([$account?->iban, $account?->bank, $account?->holder])->toBe(['GB82 WEST 1234 5698 7654 32', 'Al Noor Bank', 'TouchWood Trading'])
            ->and(bankSettingRead(BankAccountSettings::IBAN, 'eg'))->toBe('DE89 3704 0044 0532 0130 00')
            ->and(app(PaymentsApi::class)->bankAccount(bankSettingStore('eg')))->toBeNull()
            ->and($row->updated_by)->toBe($staffId)
            ->and(Carbon::parse($row->updated_at)->utc()->toDateTimeString())->toBe('2026-10-01 10:00:00')
            ->and(DB::table('platform.settings')->where('key', 'like', 'b2b.bank.%')->count())->toBe(0);
    });

    it('keeps a value already under the new name, and goes back on a rollback', function () {
        $staffId = Fx::staff(superAdmin: true);
        bankOldRows('sa', ['b2b.bank.name' => 'Old Bank'], $staffId);
        bankSettingSave(BankAccountSettings::BANK, 'sa', 'New Bank');

        bankMove('up');

        expect(bankSettingRead(BankAccountSettings::BANK, 'sa'))->toBe('New Bank');

        bankMove('down');

        expect(DB::table('platform.settings')->where('key', 'b2b.bank.name')->value('value'))->toBe('"New Bank"')
            ->and(DB::table('platform.settings')->where('key', BankAccountSettings::BANK)->count())->toBe(0);
    });
});

describe('the one-time grants (gap 8.4, b2b.md amendment 33)', function () {
    /** The real pairs, as if they had not run yet: a test's migrate ran them over no roles. */
    function bankGrantsRun(): void
    {
        DB::table('access.permission_copies')->delete();
        app(PermissionSync::class)->run();
    }

    it('gives the payments list to every role that views orders, for the same stores, and only once', function () {
        $staffId = Fx::staffWith(['sales.order.view'], ['sa', 'ae']);
        $roleId = Fx::roleOf($staffId);
        DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $staffId, 'permission' => 'sales.order.view', 'access_level' => 'SELECTED_STORES']);
        DB::table('access.role_assignment_exception_stores')->insert(['staff_user_id' => $staffId, 'permission' => 'sales.order.view', 'store_id' => Fx::storeId('sa')]);

        bankGrantsRun();

        expect(Fx::rolePermissions($roleId))->toBe([PaymentsPermissions::VIEW, 'sales.order.view'])
            ->and(DB::table('access.role_assignment_exception_stores')->where('staff_user_id', $staffId)->where('permission', PaymentsPermissions::VIEW)->pluck('store_id')->all())->toBe([Fx::storeId('sa')]);

        // Its own box from now on: unticked, it stays unticked.
        DB::table('access.role_permissions')->where('role_id', $roleId)->where('permission', PaymentsPermissions::VIEW)->delete();
        app(PermissionSync::class)->run();

        expect(Fx::rolePermissions($roleId))->toBe(['sales.order.view']);
    });

    it('leaves a role that does not view orders without it', function () {
        $roleId = Fx::roleOf(Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa']));

        bankGrantsRun();

        expect(Fx::rolePermissions($roleId))->toBe([PlatformPermissions::MEDIA_UPLOAD]);
    });

    it('moves the job from B2B to Payments for an admin role, with an exception\'s stores, and leaves nothing behind', function () {
        $staffId = Fx::staffWith([PlatformPermissions::MEDIA_UPLOAD], ['sa', 'ae'], RoleLevel::Admin);
        $roleId = Fx::roleOf($staffId);
        DB::table('access.role_permissions')->insert(['role_id' => $roleId, 'permission' => 'b2b.settings.update']);
        DB::table('access.role_assignment_exceptions')->insert(['staff_user_id' => $staffId, 'permission' => 'b2b.settings.update', 'access_level' => 'SELECTED_STORES']);
        DB::table('access.role_assignment_exception_stores')->insert(['staff_user_id' => $staffId, 'permission' => 'b2b.settings.update', 'store_id' => Fx::storeId('ae')]);

        bankGrantsRun();

        expect(Fx::rolePermissions($roleId))->toBe([PaymentsPermissions::BANK_ACCOUNT_UPDATE, PlatformPermissions::MEDIA_UPLOAD])
            ->and(DB::table('access.role_assignment_exceptions')->where('staff_user_id', $staffId)->pluck('permission')->all())->toBe([PaymentsPermissions::BANK_ACCOUNT_UPDATE])
            ->and(DB::table('access.role_assignment_exception_stores')->where('staff_user_id', $staffId)->pluck('store_id')->all())->toBe([Fx::storeId('ae')])
            ->and(DB::table('access.role_assignment_exception_stores')->where('permission', 'b2b.settings.update')->count())->toBe(0);
    });
});
