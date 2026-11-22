<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * One row of a product page's choices (catalog.md §4.5): a variant attribute of the product, in the
 * product's order (amendment 16(b)), and its values its variants hold, in the attribute's order — a
 * value no variant on sale holds is shown and cannot be picked.
 */
final readonly class ShopChoice
{
    /**
     * @param  list<ShopChoiceValue>  $values
     */
    public function __construct(
        public string $attributeId,
        public string $name,
        public bool $isColour,
        public array $values,
    ) {}
}
