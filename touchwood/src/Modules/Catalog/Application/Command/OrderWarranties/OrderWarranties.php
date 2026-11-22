<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderWarranties;

/**
 * The warranties' order — the order of the product form's choice — dragged or moved to the top or
 * the bottom (catalog.md §1.9, amendment 16(d)): the ids in their new order. Those not sent keep
 * their order, after them (P29).
 */
final readonly class OrderWarranties
{
    /**
     * @param  list<string>  $warrantyIds
     */
    public function __construct(
        public array $warrantyIds,
    ) {}
}
