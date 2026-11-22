<?php

declare(strict_types=1);

namespace Modules\Catalog\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Catalog's settings (shop-design.md §10.4, P11, P12): the two flags a card wins by itself, each store's own.
 *
 * - **Best seller**: the store's top N products by its sales rank; 0 switches the flag off.
 * - **New arrival**: listed in the store within the last N days; 0 switches it off.
 *
 * Both are compared as a card is read, never stored as a yes or no, so neither goes stale.
 */
final class CatalogSettings
{
    public const string BEST_SELLER_TOP = 'catalog.flags.best_seller_top';

    public const string NEW_ARRIVAL_DAYS = 'catalog.flags.new_arrival_days';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::BEST_SELLER_TOP, SettingScope::Store, SettingType::Integer, ['min:0', 'max:100'], 10, CatalogPermissions::LISTING_LABELS),
            new SettingDefinitionDto(self::NEW_ARRIVAL_DAYS, SettingScope::Store, SettingType::Integer, ['min:0', 'max:365'], 30, CatalogPermissions::LISTING_LABELS),
        ];
    }
}
