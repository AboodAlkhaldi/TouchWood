<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A category page's filters (catalog.md §4.5, P14), all from one read: the brands, the values of the
 * filter attributes present in what is listed — each attribute in the list's order, its values in
 * theirs — and Retail and Wholesale for a company or a guest. Each choice says how many products
 * picking it would show, the other kinds' choices kept: several of one kind show any of them.
 */
final readonly class CategoryFilters
{
    /**
     * @param  list<FilterChoice>  $brands
     * @param  list<FilterGroup>  $attributes
     * @param  list<FilterChoice>  $sides  none for an individual
     */
    public function __construct(
        public array $brands,
        public array $attributes,
        public array $sides,
    ) {}
}
