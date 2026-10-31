<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One of the store's boxes (shipping.md §1.7, §4.1 part 3): its inner sizes in mm, its most weight in grams. */
#[TypeScript]
final class BoxData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public int $lengthMm,
        public int $widthMm,
        public int $heightMm,
        public int $maxWeightG,
        public bool $active,
    ) {}
}
