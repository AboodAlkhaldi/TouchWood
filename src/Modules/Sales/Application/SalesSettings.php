<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Sales's store settings (sales.md §1.10): the return window from delivery, 14 days (owner, 2026-10-09) —
 * the flat shipping fee and its free-above amount went with stage 7, Shipping's carriers in their place
 * (§9.5 #2); and the coupon box's limit and the waiting orders a customer may have, one for
 * every store. Admin-only: they decide money.
 * Amounts in the currency's smallest unit.
 */
final class SalesSettings
{
    public const string RETURN_WINDOW_DAYS = 'sales.returns.window_days';

    /**
     * Shipping's store-wide free-above amount (shipping.md §1.5), read by its key for the cart's free-shipping
     * bar (gap 6.6): declared and kept by Shipping — Sales only reads it; 0 is "not set".
     */
    public const string SHIPPING_FREE_FOR_ALL = 'shipping.free.above_all';

    /**
     * The coupon box's limit (§1.1, answer 50): codes naming no coupon a customer may type in the window,
     * and the window in minutes. One pair for every store (owner, 2026-10-28), so changed only by an
     * admin of every store or a Super Admin.
     */
    public const string COUPON_WRONG_CODES = 'sales.coupons.wrong_codes';

    public const string COUPON_WINDOW_MINUTES = 'sales.coupons.window_minutes';

    /**
     * Orders waiting for approval a customer may have in one store (§1.3, answer 51): one number for
     * every store (owner, 2026-10-28), changed only by an admin of every store or a Super Admin.
     */
    public const string WAITING_ORDERS = 'sales.orders.waiting_most';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::RETURN_WINDOW_DAYS, SettingScope::Store, SettingType::Integer, ['min:1', 'max:365'], 14, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::COUPON_WRONG_CODES, SettingScope::Global, SettingType::Integer, ['min:1', 'max:20'], 5, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::COUPON_WINDOW_MINUTES, SettingScope::Global, SettingType::Integer, ['min:1', 'max:60'], 10, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::WAITING_ORDERS, SettingScope::Global, SettingType::Integer, ['min:1', 'max:20'], 3, SalesPermissions::SETTINGS_UPDATE),
        ];
    }
}
