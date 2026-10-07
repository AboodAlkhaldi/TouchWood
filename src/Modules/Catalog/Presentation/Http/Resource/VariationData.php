<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A variation — an attribute set (catalog.md §1.7, §4.4 S4): its attributes in order, whether variants
 * are built on it, and how many products take it.
 */
#[TypeScript]
final class VariationData extends Data
{
    /**
     * @param  list<string>  $attributeIds  in the set's order
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public bool $active,
        public array $attributeIds,
        public bool $builtOn,
        public int $products,
    ) {}
}
