<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One value of an attribute (catalog.md §1.7): its swatch, as `#rrggbb`, on a colour attribute.
 */
#[TypeScript]
final class ValueData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public ?string $swatch,
        public bool $active,
        public int $position,
        public bool $inUse,
    ) {}
}
