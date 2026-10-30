<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListPackingSizes;

/**
 * The store's sizes packed by hand (`byHand` true) - or, for the box that adds one, the store's sizes
 * not yet packed by hand (false) - found by Catalog's search, a page at a time (shipping.md §1.7; gap 5.3).
 */
final readonly class ListPackingSizes
{
    public function __construct(
        public string $storeId,
        public bool $byHand,
        public ?string $text = null,
        public ?string $after = null,
    ) {}
}
