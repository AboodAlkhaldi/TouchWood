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

    /** The space kept on every side of a box's inside, in millimetres (§1.7): admins and staff change it. */
    public const string PADDING_MM = 'shipping.boxes.padding_mm';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            // Both to be edited on Shipping's own screens - the amount in the currency on Carriers, the padding
            // on Boxes (§4.1). Each stays on Platform's Settings page until its screen's field ships; the
            // screens session sets `onSettingsPage: false` with it, so neither is ever without a screen.
            new SettingDefinitionDto(self::FREE_ABOVE_ALL, SettingScope::Store, SettingType::Integer, ['min:0', 'max:1000000000'], 0, ShippingPermissions::PRICES_MANAGE),
            new SettingDefinitionDto(self::PADDING_MM, SettingScope::Store, SettingType::Integer, ['min:0', 'max:100'], 10, ShippingPermissions::BOXES_MANAGE),
        ];
    }
}
