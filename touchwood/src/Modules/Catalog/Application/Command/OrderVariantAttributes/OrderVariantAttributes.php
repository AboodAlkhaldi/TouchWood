<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderVariantAttributes;

/**
 * A product's variant attributes put in a new order — the order of its Variants tab's columns and
 * of the shop's pickers (catalog.md §1.7, P27, P29).
 */
final readonly class OrderVariantAttributes
{
    /**
     * @param  list<string>  $attributeIds  those it sends first, in this order
     */
    public function __construct(
        public string $productId,
        public array $attributeIds,
    ) {}
}
