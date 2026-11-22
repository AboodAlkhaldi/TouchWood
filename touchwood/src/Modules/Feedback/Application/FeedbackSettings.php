<?php

declare(strict_types=1);

namespace Modules\Feedback\Application;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Feedback's one setting (feedback.md §1.2): whether a store's lists show stars and offer the rating
 * sort — on by default, switched by admins and Super Admins only (owner, 2026-10-09). The product page
 * shows its reviews and rating either way.
 */
final class FeedbackSettings
{
    public const string RATINGS_IN_LISTS = 'feedback.ratings.in_lists';

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::RATINGS_IN_LISTS, SettingScope::Store, SettingType::Boolean, [], true, FeedbackPermissions::SETTINGS_UPDATE),
        ];
    }
}
