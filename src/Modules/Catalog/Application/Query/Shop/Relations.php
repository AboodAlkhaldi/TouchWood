<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * What a product page suggests (catalog.md §1.10), only products listed in the store being viewed.
 */
final readonly class Relations
{
    /**
     * @param  list<ProductCard>  $mayAlsoLike  hand-picked "Related"; filled from the same category, then brand, only when none was picked
     * @param  list<ProductCard>  $goesWith  hand-picked only
     */
    public function __construct(
        public array $mayAlsoLike,
        public array $goesWith,
    ) {}
}
