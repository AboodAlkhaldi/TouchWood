<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderBrands;

/**
 * The brands' order, dragged or moved to the top or the bottom (catalog.md §1.6, amendment 16(d)):
 * the ids in their new order. Those not sent keep their order, after them (P29).
 */
final readonly class OrderBrands
{
    /**
     * @param  list<string>  $brandIds
     */
    public function __construct(
        public array $brandIds,
    ) {}
}
