<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A submitted search's answer (catalog.md §1.11): the best matches first, how many there were, and
 * where the next page starts (P16).
 */
final readonly class SearchResults
{
    /**
     * @param  list<ProductCard>  $cards
     * @param  string|null  $next  the cursor of the next page; null on the last
     */
    public function __construct(
        public array $cards,
        public int $total,
        public ?string $next = null,
    ) {}
}
