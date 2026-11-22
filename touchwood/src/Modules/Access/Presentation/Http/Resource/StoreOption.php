<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A store, for the boxes that say where a role reaches (frontend.md 3.3, C6). An off store the reader
 * covers is offered too, marked Off: a staff member keeps it while it is off, and an admin may still
 * take it away or give it (access.md amendment 58(b); owner, 2026-10-03).
 */
#[TypeScript]
final class StoreOption extends Data
{
    public function __construct(
        public string $id,
        /** Already in the language the panel is being read in. */
        public string $name,
        public bool $isActive = true,
    ) {}
}
