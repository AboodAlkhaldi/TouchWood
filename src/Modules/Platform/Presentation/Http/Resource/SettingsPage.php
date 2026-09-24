<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * E4 - the settings (frontend.md 3.5).
 */
#[TypeScript]
final class SettingsPage extends Data
{
    /**
     * @param  list<SettingGroup>  $groups  a module with nothing for this person is not here at all
     */
    public function __construct(
        public array $groups,
        /** The store a per-store setting applies to, named for the screen to say so. */
        public ?string $storeName,
    ) {}
}
