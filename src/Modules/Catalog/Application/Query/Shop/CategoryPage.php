<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A category's page (catalog.md §4.5): its name, its slug here and in the other language (P19), the
 * cards the shopper's filters and sort leave, the filters with their counts (P14), and the choices
 * the page applied — those it still offers.
 */
final readonly class CategoryPage
{
    public function __construct(
        public string $categoryId,
        public string $name,
        public string $slug,
        public CardPage $products,
        public ?string $otherSlug = null,
        public ?CategoryFilters $filters = null,
        public ?ShopFilters $chosen = null,
    ) {}
}
