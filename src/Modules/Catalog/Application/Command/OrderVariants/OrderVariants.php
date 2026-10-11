<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderVariants;

/**
 * A product's variants put in a new order by dragging (catalog.md §1.2, P29, amendment 16(d)): the
 * order of its Variants tab and of the shop's choices.
 */
final readonly class OrderVariants
{
    /**
     * @param  list<string>  $variantIds  those it sends first, in this order
     */
    public function __construct(
        public string $productId,
        public array $variantIds,
    ) {}
}
