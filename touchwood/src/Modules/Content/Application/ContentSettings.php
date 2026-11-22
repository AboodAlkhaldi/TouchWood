<?php

declare(strict_types=1);

namespace Modules\Content\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Content's settings (shop-design.md §10.2, Q3, P8): the working days the product page says a delivery takes - words
 * only, nothing to do with the carriers or the checkout's shipping (owner, 2026-11-16). Each store's own, 4 by default.
 */
final class ContentSettings
{
    public const string DELIVERY_WORKING_DAYS = 'content.delivery.working_days';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::DELIVERY_WORKING_DAYS, SettingScope::Store, SettingType::Integer, ['min:1', 'max:60'], 4, ContentPermissions::HOMEPAGE_MANAGE),
        ];
    }
}
