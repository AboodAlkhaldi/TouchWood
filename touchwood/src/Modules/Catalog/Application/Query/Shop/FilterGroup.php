<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A filter attribute on a category's page (P14) and its values present there, in their order.
 */
final readonly class FilterGroup
{
    /**
     * @param  list<FilterChoice>  $choices
     */
    public function __construct(
        public string $attributeId,
        public string $name,
        public array $choices,
    ) {}
}
