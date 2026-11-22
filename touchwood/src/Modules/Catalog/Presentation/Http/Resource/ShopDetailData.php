<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a variant's details: an attribute and its value, with its unit, and whether it is a measure
 * (a number with its unit), drawn in the mono face (flags-2026-11.md S2.1, F7).
 */
#[TypeScript]
final class ShopDetailData extends Data
{
    public function __construct(
        public string $name,
        public string $value,
        public bool $measure,
    ) {}
}
