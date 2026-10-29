<?php

declare(strict_types=1);

namespace Modules\Shipping\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Shipping's store settings (shipping.md §5): the store-wide free-above amount that overrides every
 * carrier's own while it is set (§1.5) - in the currency's smallest unit, 0 = off. Admins: it decides money.
 */
final class ShippingSettings
{
    public const string FREE_ABOVE_ALL = 'shipping.free.above_all';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::FREE_ABOVE_ALL, SettingScope::Store, SettingType::Integer, ['min:0', 'max:1000000000'], 0, ShippingPermissions::PRICES_MANAGE),
        ];
    }
}
