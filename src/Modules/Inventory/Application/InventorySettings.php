<?php

declare(strict_types=1);

namespace Modules\Inventory\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Inventory's settings, declared through Platform's registry at boot (inventory.md §1.2).
 *
 * **The store's default low-stock threshold**: a size with no threshold of its own uses it - 10 pieces
 * unless the store sets another (owner, 2026-10-09). Per store; changed with Manage Stock, as every
 * threshold is (owner, 2026-10-09). From 0 to 100,000, as each size's own threshold (owner,
 * 2026-10-10).
 */
final class InventorySettings
{
    public const string LOW_STOCK_DEFAULT = 'inventory.low_stock.default';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(
                self::LOW_STOCK_DEFAULT, SettingScope::Store, SettingType::Integer, ['min:0', 'max:100000'], 10, InventoryPermissions::STOCK_MANAGE,
            ),
        ];
    }
}
