<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One import (catalog.md §4.4 S11): its four parts, each a page of its own in the address (`?part=`,
 * P12), the open part's data only - the names, the products whose codes the catalog holds, every
 * product for the changes before bringing in, or the products a hundred at a time (P13).
 */
#[TypeScript]
final class ImportPage extends Data
{
    /**
     * @param  list<ImportNameData>|null  $names  the names part
     * @param  list<ImportProductData>|null  $products  the open part's products
     * @param  list<BrandOptionData>|null  $brands  for It Means… and the changes
     * @param  list<CategoryOptionData>|null  $categories
     * @param  list<WarrantyOptionData>|null  $warranties
     * @param  list<AttributeChoiceData>|null  $attributes
     */
    public function __construct(
        public ImportHeadData $import,
        public string $part,
        public ImportWaitingData $waiting,
        public ?array $names,
        public ?array $products,
        public int $page,
        public bool $more,
        public ?array $brands,
        public ?array $categories,
        public ?array $warranties,
        public ?array $attributes,
    ) {}
}
