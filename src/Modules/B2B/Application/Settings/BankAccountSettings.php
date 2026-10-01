<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Settings;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Modules\Platform\Public\PlatformPermissions;

/**
 * The bank account an approved company transfers to (b2b.md §2.3, amendment 12(b)): three settings
 * per store — a store's bank is its own —, changed under Platform's store settings job, no B2B job of
 * its own. **They start empty**, meaning not set yet (a text setting that may be empty, platform.md
 * §1.3), and a company is shown them only once all three are filled in, and only while it is
 * approved. Payments owns them from stage 7.
 */
final class BankAccountSettings
{
    public const string IBAN = 'b2b.bank.iban';

    public const string BANK = 'b2b.bank.name';

    public const string HOLDER = 'b2b.bank.holder';

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
            $key, SettingScope::Store, SettingType::Text, $rules, '', PlatformPermissions::SETTINGS_UPDATE, mayBeEmpty: true,
        );
    }
}
