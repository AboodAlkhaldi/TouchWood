<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewProduct;

use Modules\Catalog\Application\Query\Products\AttributeChoice;
use Modules\Catalog\Application\Query\Products\ProductCore;
use Modules\Catalog\Application\Query\Products\ProductOptions;
use Modules\Catalog\Application\Query\Products\RelatedRow;
use Modules\Catalog\Application\Query\Products\VariantView;

/**
 * One product's page (catalog.md §4.4 S9): the product above its tabs, what it still lacks to be made
 * ready (`Readiness`'s rules), what the reader may do to it, each gallery photo's size state, and the
 * open tab's own data - the others' are null.
 */
final readonly class ProductView
{
    /**
     * @param  list<string>  $missing  what it lacks to be made ready: `name_en`, `description_ar`, `description_en`, `category`, `variants`, `photos`
     * @param  array<string, string>  $photoStates  gallery media id => PENDING, READY or FAILED
     * @param  list<VariantView>|null  $variants
     * @param  list<AttributeChoice>|null  $attributes
     * @param  list<string>|null  $searchWords
     * @param  list<string>|null  $filterValueIds
     * @param  list<RelatedRow>|null  $related
     */
    public function __construct(
        public ProductCore $product,
        public string $tab,
        public array $missing,
        public array $photoStates,
        public bool $mayUpdate,
        public bool $mayPublish,
        public bool $mayArchive,
        public bool $mayCorrectCode,
        public ?ProductOptions $options = null,
        public ?array $variants = null,
        public ?array $attributes = null,
        public ?array $searchWords = null,
        public ?array $filterValueIds = null,
        public ?array $related = null,
    ) {}
}
