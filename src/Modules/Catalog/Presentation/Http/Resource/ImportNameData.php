<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A name the file uses that the catalog lacks (§1.12, part 1): its kind, as written, how many products
 * use it, how many catalog items answer to it (amendment 8(d)), and the decision.
 */
#[TypeScript]
final class ImportNameData extends Data
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $written,
        public ?string $attribute,
        public ?string $attributeKind,
        public ?string $decision,
        public ?string $targetId,
        public ?string $nameAr,
        public ?string $nameEn,
        public int $products,
        public int $matches,
        public ?string $slugAr,
        public ?string $slugEn,
        public bool $addressTaken,
        /** A value's attribute in the catalog, as the decision would read it: its values are offered. */
        public ?string $valueAttributeId,
    ) {}
}
