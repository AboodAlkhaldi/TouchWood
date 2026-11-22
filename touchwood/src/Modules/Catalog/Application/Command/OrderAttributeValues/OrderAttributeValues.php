<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderAttributeValues;

/**
 * One attribute's values in their order — the order of the shop's pickers, 45 → 60 → 80 cm —
 * dragged or moved to the top or the bottom on the attribute's page (catalog.md §1.7, amendment
 * 16(d)): the ids in their new order. Those not sent keep their order, after them (P29).
 */
final readonly class OrderAttributeValues
{
    /**
     * @param  list<string>  $valueIds
     */
    public function __construct(
        public string $attributeId,
        public array $valueIds,
    ) {}
}
