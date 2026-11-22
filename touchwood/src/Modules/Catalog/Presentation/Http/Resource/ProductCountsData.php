<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How much each tab of a product's page holds, for its tab's label (catalog.md §4.4 S9).
 */
#[TypeScript]
final class ProductCountsData extends Data
{
    public function __construct(
        public int $variants,
        public int $photos,
        public int $searchWords,
        public int $filterValues,
        public int $related,
        public int $goesWith,
    ) {}
}
