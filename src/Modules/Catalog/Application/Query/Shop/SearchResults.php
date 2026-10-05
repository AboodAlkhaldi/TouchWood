<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A submitted search's answer (catalog.md §1.11): the best matches first, and how many there were.
 */
final readonly class SearchResults
{
    /**
     * @param  list<ProductCard>  $cards
     */
    public function __construct(
        public array $cards,
        public int $total,
    ) {}
}
