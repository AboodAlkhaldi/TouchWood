<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderLabels;

/**
 * The labels' order — the order on a card — dragged or moved to the top or the bottom (catalog.md
 * §1.8, amendment 16(d)): the ids in their new order. Those not sent keep their order, after them
 * (P29).
 */
final readonly class OrderLabels
{
    /**
     * @param  list<string>  $labelIds
     */
    public function __construct(
        public array $labelIds,
    ) {}
}
