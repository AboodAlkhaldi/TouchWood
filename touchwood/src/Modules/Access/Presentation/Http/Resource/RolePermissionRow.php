<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One action a role holds, as a screen shows it (frontend.md 3.4).
 */
#[TypeScript]
final class RolePermissionRow extends Data
{
    public function __construct(
        public string $name,
        /** Already in the language the panel is being read in. */
        public string $label,
        public string $group,
        /** A store-free action reaches every store by its nature (access.md amendment 4). */
        public bool $storeFree,
    ) {}
}
