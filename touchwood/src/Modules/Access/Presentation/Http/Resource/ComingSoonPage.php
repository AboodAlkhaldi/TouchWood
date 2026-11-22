<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A screen whose module is not built yet (frontend.md 2.2), offered to Super Admins only.
 */
#[TypeScript]
final class ComingSoonPage extends Data
{
    public function __construct(
        /** The entry as it reads in the menu, already translated by the server. */
        public string $label,
    ) {}
}
