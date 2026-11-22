<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One store in a screen's own store filter (platform.md §9.10).
 */
#[TypeScript]
final class StoreOption extends Data
{
    public function __construct(
        /** What the address carries: `?store=sa`. */
        public string $code,
        public string $name,
        /** False for an off store, offered only to a Super Admin and marked Off (§1.6). */
        public bool $isActive,
    ) {}
}
