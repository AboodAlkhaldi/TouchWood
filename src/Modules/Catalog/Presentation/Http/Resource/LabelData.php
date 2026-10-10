<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A label (catalog.md §1.8, §4.4 S5): its names, its look — one of Geist Badge's ten — and on how many
 * products the stores show it.
 */
#[TypeScript]
final class LabelData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        /** gray, blue, green, amber, red, or one of them with -subtle (amendment 1(e)). */
        public string $tone,
        public bool $active,
        public int $position,
        public int $products,
    ) {}
}
