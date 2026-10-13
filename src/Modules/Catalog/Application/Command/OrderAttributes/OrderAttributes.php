<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderAttributes;

/**
 * The attributes' order — the order of the shop's filters — dragged or moved to the top or the
 * bottom (catalog.md §1.7, amendment 16(d)): the ids in their new order. Those not sent keep their
 * order, after them (P29).
 */
final readonly class OrderAttributes
{
    /**
     * @param  list<string>  $attributeIds
     */
    public function __construct(
        public array $attributeIds,
    ) {}
}
