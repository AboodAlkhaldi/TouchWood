<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The settings one module declares (frontend.md 3.5, E4).
 *
 * Grouped by module rather than by permission, which is why Access's numbers sit in one Access
 * section although a store's own settings and the staff security numbers carry different
 * permissions (owner, 2026-09-22).
 */
#[TypeScript]
final class SettingGroup extends Data
{
    /**
     * @param  list<SettingRowData>  $settings
     */
    public function __construct(
        public string $module,
        /** The module's name, in the language the panel is being read in. */
        public string $label,
        public array $settings,
    ) {}
}
