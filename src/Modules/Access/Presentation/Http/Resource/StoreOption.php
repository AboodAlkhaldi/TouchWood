<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store, for the boxes that say where a role reaches (frontend.md 3.3, C6).
 */
#[TypeScript]
final class StoreOption extends Data
{
    public function __construct(
        public string $id,
        /** Already in the language the panel is being read in. */
        public string $name,
    ) {}
}
