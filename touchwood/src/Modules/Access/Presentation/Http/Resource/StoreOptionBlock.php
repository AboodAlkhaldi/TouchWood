<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the person's stores, as Home's switcher offers it (access.md amendment 64).
 */
#[TypeScript]
final class StoreOptionBlock extends Data
{
    public function __construct(
        /** What the address carries: `/admin?store=sa`. */
        public string $code,
        public string $name,
        /** False for an off store: a Super Admin's alone, marked Off. */
        public bool $isActive,
    ) {}
}
