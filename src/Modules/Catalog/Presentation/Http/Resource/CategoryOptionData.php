<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A category a product may sit in - active, with no sub-category - named by its path from the top
 * (catalog.md §4.4 S9).
 */
#[TypeScript]
final class CategoryOptionData extends Data
{
    /**
     * @param  string  $pathAr  its path in Arabic, the top first
     */
    public function __construct(
        public string $id,
        public string $pathAr,
        public string $pathEn,
    ) {}
}
