<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Settings;

use Modules\Payments\Application\PaymentsPermissions;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * The bank account an approved company transfers to (payments.md §1.9, b2b.md amendment 33): three
 * settings per store - a store's bank is its own -, changed only by an admin, under
 * `payments.bank_account.update`. **They start empty**, meaning not set yet (a text setting that may be
 * empty, platform.md §1.3), and a company is shown them only once all three are filled in, and only
 * while it is approved.
 */
final class BankAccountSettings
{
    public const string IBAN = 'payments.bank.iban';

    public const string BANK = 'payments.bank.name';

    public const string HOLDER = 'payments.bank.holder';

    /** One line of text: a bank's or a holder's name. */
    private const string ONE_LINE = 'regex:/\A[^\p{Cc}]+\z/u';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            // Written in groups of four, an IBAN of 34 characters takes 42.
            self::setting(self::IBAN, ['max:42', new IbanRule]),
            self::setting(self::BANK, ['max:100', self::ONE_LINE]),
            self::setting(self::HOLDER, ['max:100', self::ONE_LINE]),
        ];
    }

    /**
     * @param  list<mixed>  $rules
     */
    private static function setting(string $key, array $rules): SettingDefinitionDto
    {
        return new SettingDefinitionDto(
            $key, SettingScope::Store, SettingType::Text, $rules, '', PaymentsPermissions::BANK_ACCOUNT_UPDATE, mayBeEmpty: true,
        );
    }
}
