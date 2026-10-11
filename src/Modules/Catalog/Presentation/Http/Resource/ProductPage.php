<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One product's page (catalog.md §4.4 S9): the product above its tabs, what it lacks to be made
 * ready, what the reader may do, its gallery, and the open tab's own data - the others' are null.
 */
#[TypeScript]
final class ProductPage extends Data
{
    /**
     * @param  list<string>  $missing  what it lacks to be made ready: name_en, description_ar, description_en, category, variants, photos
     * @param  list<PhotoData>  $gallery  the card's photo first
     * @param  list<BrandOptionData>|null  $brands
     * @param  list<CategoryOptionData>|null  $categories
     * @param  list<WarrantyOptionData>|null  $warranties
     * @param  list<VariantData>|null  $variants
     * @param  list<AttributeChoiceData>|null  $attributes
     * @param  list<string>|null  $searchWords
     * @param  list<string>|null  $filterValueIds
     * @param  list<RelatedData>|null  $related
     * @param  list<ProductRowData>|null  $found  the ready products a search for a related product found
     */
    public function __construct(
        public ProductHeadData $product,
        public string $tab,
        public array $missing,
        public array $gallery,
        public bool $mayUpdate,
        public bool $mayPublish,
        public bool $mayArchive,
        public bool $mayCorrectCode,
        public ?array $brands,
        public ?array $categories,
        public ?array $warranties,
        public ?array $variants,
        public ?array $attributes,
        public ?array $searchWords,
        public ?array $filterValueIds,
        public ?array $related,
        public ?array $found,
    ) {}
}
