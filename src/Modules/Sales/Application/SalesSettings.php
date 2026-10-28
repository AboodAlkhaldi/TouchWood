<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Sales's store settings (sales.md §1.10): until Shipping (stage 7) one flat fee a store and the goods
 * total from which shipping is free — 0, never free — and the return window from delivery, 14 days
 * (owner, 2026-10-09); and the coupon box's limit and the waiting orders a customer may have, one for
 * every store. Admin-only: they decide money.
 * Amounts in the currency's smallest unit.
 */
final class SalesSettings
{
    public const string FLAT_FEE = 'sales.shipping.flat_fee';

    public const string FREE_ABOVE = 'sales.shipping.free_above';

    public const string RETURN_WINDOW_DAYS = 'sales.returns.window_days';

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
            new SettingDefinitionDto(self::FLAT_FEE, SettingScope::Store, SettingType::Integer, ['min:0', 'max:100000000'], 0, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::FREE_ABOVE, SettingScope::Store, SettingType::Integer, ['min:0', 'max:1000000000'], 0, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::RETURN_WINDOW_DAYS, SettingScope::Store, SettingType::Integer, ['min:1', 'max:365'], 14, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::COUPON_WRONG_CODES, SettingScope::Global, SettingType::Integer, ['min:1', 'max:20'], 5, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::COUPON_WINDOW_MINUTES, SettingScope::Global, SettingType::Integer, ['min:1', 'max:60'], 10, SalesPermissions::SETTINGS_UPDATE),
            new SettingDefinitionDto(self::WAITING_ORDERS, SettingScope::Global, SettingType::Integer, ['min:1', 'max:20'], 3, SalesPermissions::SETTINGS_UPDATE),
        ];
    }
}
