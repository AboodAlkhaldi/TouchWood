<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A brand a product may carry (catalog.md §4.4 S8, S9); a new choice takes an active one.
 */
#[TypeScript]
final class BrandOptionData extends Data
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $isDefault,
        public bool $active,
    ) {}
}
