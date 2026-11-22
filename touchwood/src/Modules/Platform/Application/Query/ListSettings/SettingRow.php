<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListSettings;

use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;

/**
 * One setting a person may change (frontend.md 3.5, E4).
 */
final readonly class SettingRow
{
    public function __construct(
        public string $key,
        /** The module that declared it, taken from the key: "platform.media.max_bytes" is Platform's. */
        public string $module,
        public SettingScope $scope,
        public SettingType $type,
        /**
         * Its value now - or null when the setting is sensitive.
         *
         * A sensitive setting never shows its value anywhere (platform.md 1.3), which includes the
         * screen that changes it: it is written, never read back.
         */
        public mixed $value,
        /** Its value when nothing has been stored, and null for a sensitive one, for the same reason. */
        public mixed $default,
        public bool $isDefault,
        public bool $sensitive,
        /** The smallest the module allows, where it said. */
        public ?int $min,
        /** And the largest. */
        public ?int $max,
    ) {}
}
