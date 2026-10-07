<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * An active brand a product may carry (catalog.md §4.4 S8, S9).
 */
#[TypeScript]
final class BrandOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $isDefault,
    ) {}
}
