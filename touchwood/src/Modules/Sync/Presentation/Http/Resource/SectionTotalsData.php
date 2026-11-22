<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How many lines each section of a read holds (Y2's tabs, Q32).
 */
#[TypeScript]
final class SectionTotalsData extends Data
{
    public function __construct(
        public int $prices,
        public int $stock,
        public int $reported,
        public int $codes,
    ) {}
}
