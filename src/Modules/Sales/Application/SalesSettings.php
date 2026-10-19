<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Sales's store settings (sales.md §1.10): until Shipping (stage 7) one flat fee a store and the goods
 * total from which shipping is free — 0, never free — and the return window from delivery, 14 days
 * (owner, 2026-10-09). Admin-only: they decide money. Amounts in the currency's smallest unit.
 */
final class SalesSettings
{
    public const string FLAT_FEE = 'sales.shipping.flat_fee';

    public const string FREE_ABOVE = 'sales.shipping.free_above';

    public const string RETURN_WINDOW_DAYS = 'sales.returns.window_days';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::FLAT_FEE, SettingScope::Store, SettingType::Integer, ['min:0', 'max:100000000'], 0, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::FREE_ABOVE, SettingScope::Store, SettingType::Integer, ['min:0', 'max:1000000000'], 0, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::RETURN_WINDOW_DAYS, SettingScope::Store, SettingType::Integer, ['min:1', 'max:365'], 14, SalesPermissions::SETTINGS_UPDATE),
        ];
    }
}
