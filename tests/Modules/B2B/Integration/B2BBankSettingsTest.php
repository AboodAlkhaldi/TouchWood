<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\B2B\Application\Settings\BankAccountSettings;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Settings\InMemorySettingsSectionLines;
use Modules\Platform\Domain\Exception\InvalidSettingValue;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Contracts\SettingsSectionLines;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Domain\ValueObject\StoreId;

use function Pest\Laravel\seed;

/*
| The bank account an approved company transfers to (b2b.md §2.3, amendment 12(b)): three Platform
| settings per store, declared by B2B, changed under Platform's store settings job. They start empty,
| meaning not set yet.
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

it('declares the IBAN, the bank and the holder: per store, text, empty until set, under the store settings job', function () {
    $keys = [BankAccountSettings::IBAN, BankAccountSettings::BANK, BankAccountSettings::HOLDER];

    foreach ($keys as $key) {
        $definition = app(SettingsRegistry::class)->definition($key);

        expect($definition)->not->toBeNull()
            ->and($definition?->scope)->toBe(SettingScope::Store)
            ->and($definition?->type)->toBe(SettingType::Text)
            ->and($definition?->default)->toBe('')
            ->and($definition?->mayBeEmpty)->toBeTrue()
            ->and($definition?->permission)->toBe(PlatformPermissions::SETTINGS_UPDATE)
            // Shown to every approved company of the store: nothing about it is secret.
            ->and($definition?->sensitive)->toBeFalse()
            ->and(bankSettingRead($key, 'sa'))->toBe('');
    }

    expect($keys)->toBe(['b2b.bank.iban', 'b2b.bank.name', 'b2b.bank.holder']);
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

it('names the section and the three settings on the settings screen, in Arabic and English', function () {
    foreach (['ar', 'en'] as $locale) {
        $keys = ['b2b::settings.module'];

        foreach (app(SettingsRegistry::class)->all() as $definition) {
            if (str_starts_with($definition->key, 'b2b.')) {
                $keys[] = $definition->labelKey();
            }
        }

        expect($keys)->toHaveCount(4);

        foreach ($keys as $key) {
            $name = trans($key, [], $locale);

            expect(is_string($name) && $name !== '' && $name !== $key)->toBeTrue("{$key} has no {$locale} name");
        }
    }
});

it('gives the settings section no line on a page showing no store: the account is per store (amendment 13(c))', function () {
    bankSettingSave(BankAccountSettings::IBAN, 'sa', 'GB82 WEST 1234 5698 7654 32');
    bankSettingSave(BankAccountSettings::BANK, 'sa', 'Al Noor Bank');
    bankSettingSave(BankAccountSettings::HOLDER, 'sa', 'TouchWood Trading');
    // The registry behind Platform's contract, where B2B registered its line at boot; read in English
    // here, as the panel's language decides it there.
    app()->setLocale('en');
    $line = app(InMemorySettingsSectionLines::class)->for('b2b');

    expect(app(SettingsSectionLines::class))->toBe(app(InMemorySettingsSectionLines::class))
        ->and($line?->line(null))->toBeNull()
        ->and($line?->line(bankSettingStore('sa')))->toBe('Bank transfer: on')
        ->and($line?->line(bankSettingStore('eg')))->toBe('Bank transfer: temporarily off — fill in all three to turn it on.');
});
