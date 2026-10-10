<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A product as the products list shows it (catalog.md §4.4 S8): never a price or stock (stage 5).
 */
#[TypeScript]
final class ProductRowData extends Data
{
    /**
     * @param  list<string>  $codes
     * @param  string|null  $photo  its card photo's thumbnail address, once its sizes are ready
     * @param  int  $variants  its variants not archived
     * @param  list<string>  $onIn  the codes of the stores the reader covers where it is on
     * @param  string|null  $storeState  with a store chosen: ON, OFF, NOT_CHOSEN or NOT_AVAILABLE
     * @param  int  $storeVariantsOn  with a store chosen: how many of its variants are on there
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public array $codes,
        public string $stage,
        public string $brandNameAr,
        public string $brandNameEn,
        public ?string $categoryNameAr,
        public ?string $categoryNameEn,
        public ?string $photo,
        public int $variants,
        public array $onIn,
        public ?string $storeState,
        public int $storeVariantsOn,
    ) {}
}
