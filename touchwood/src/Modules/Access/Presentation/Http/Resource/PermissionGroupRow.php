<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A business area, as the role editor and the comparison table name it (stage 2b, P2).
 */
#[TypeScript]
final class PermissionGroupRow extends Data
{
    public function __construct(
        public string $key,
        /** Already in the language the panel is being read in. */
        public string $label,
    ) {}
}
