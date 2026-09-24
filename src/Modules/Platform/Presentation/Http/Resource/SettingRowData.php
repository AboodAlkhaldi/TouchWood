<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One setting on the settings screen (frontend.md 3.5, E4).
 *
 * Only the settings this person may change reach a screen at all, so there is no "read only" here:
 * a row they may not change was never sent.
 */
#[TypeScript]
final class SettingRowData extends Data
{
    public function __construct(
        public string $key,
        /** Already in the language the panel is being read in. */
        public string $label,
        public string $scope,
        public string $type,
        /** Null for a sensitive setting, which is written and never read back (platform.md 1.3). */
        public mixed $value,
        public mixed $default,
        /** Whether nothing has been stored and the default is what is in force. */
        public bool $isDefault,
        public bool $sensitive,
        public ?int $min,
        public ?int $max,
    ) {}
}
