<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Media;

use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\MediaVisibility;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * Upload limits are global settings, so they change without a deploy (Platform spec §1.4). The
 * accepted types stay fixed in code for safety.
 */
final class MediaSettings
{
    public const string MAX_PUBLIC_BYTES = 'platform.media.max_public_bytes';

    public const string MAX_PRIVATE_BYTES = 'platform.media.max_private_bytes';

    private const int TEN_MEGABYTES = 10 * 1024 * 1024;

    private const int HUNDRED_MEGABYTES = 100 * 1024 * 1024;

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return [
            new SettingDefinitionDto(self::MAX_PUBLIC_BYTES, SettingScope::Global, SettingType::Integer, ['min:1', 'max:'.self::HUNDRED_MEGABYTES], self::TEN_MEGABYTES, 'platform.settings.update'),
            new SettingDefinitionDto(self::MAX_PRIVATE_BYTES, SettingScope::Global, SettingType::Integer, ['min:1', 'max:'.self::HUNDRED_MEGABYTES], self::TEN_MEGABYTES, 'platform.settings.update'),
        ];
    }

    public static function maxBytesKey(MediaVisibility $visibility): string
    {
        return $visibility === MediaVisibility::Public ? self::MAX_PUBLIC_BYTES : self::MAX_PRIVATE_BYTES;
    }
}
