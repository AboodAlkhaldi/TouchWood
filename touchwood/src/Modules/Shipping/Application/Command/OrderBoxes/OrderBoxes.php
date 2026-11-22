<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\OrderBoxes;

/**
 * The store's boxes in a new order (drag, or Move to Top / Bottom - catalog.md amendment 16): those sent
 * first, in that order, the rest after them in theirs. A tie between two boxes the same size goes to the
 * first in the list.
 */
final readonly class OrderBoxes
{
    /**
     * @param  list<string>  $boxIds
     */
    public function __construct(
        public string $storeId,
        public array $boxIds,
    ) {}
}
