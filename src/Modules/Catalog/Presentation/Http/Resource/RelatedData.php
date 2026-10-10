<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product picked for another's "You May Also Like" or "Goes With" (catalog.md §1.10, §4.4 S9).
 */
#[TypeScript]
final class RelatedData extends Data
{
    /**
     * @param  string  $kind  RELATED or GOES_WITH
     * @param  list<string>  $codes
     */
    public function __construct(
        public string $kind,
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public array $codes,
        public string $stage,
    ) {}
}
