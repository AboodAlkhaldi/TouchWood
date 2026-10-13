<?php

declare(strict_types=1);

namespace Modules\Pricing\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Pricing's settings, declared through Platform's registry at boot (pricing.md §1.12).
 *
 * **The store's discount ceiling** (`max_discount_percent`, handoff §11.3): how deep any sale, category
 * discount, coupon or points may go, in percent of the price - 30 unless an admin sets another (owner,
 * 2026-10-08). Moved here from Promotions, which reads it (owner, 2026-10-13). One stored value, so the
 * settings screen and any Pricing screen always show the same.
 */
final class PricingSettings
{
    public const string MAX_DISCOUNT_PERCENT = 'pricing.ceiling.max_discount_percent';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(
                self::MAX_DISCOUNT_PERCENT, SettingScope::Store, SettingType::Integer, ['min:0', 'max:100'], 30, PricingPermissions::CEILING_MANAGE,
            ),
        ];
    }
}
