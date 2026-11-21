<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of Home's periods, as its menu offers it (dashboard.md §3.3), its words in the reader's language.
 */
#[TypeScript]
final class HomePeriodBlock extends Data
{
    public function __construct(
        /** What the address carries: `/admin?period=month`. */
        public string $value,
        public string $label,
    ) {}
}
