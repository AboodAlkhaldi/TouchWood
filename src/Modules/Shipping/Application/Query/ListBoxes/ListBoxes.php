<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListBoxes;

/**
 * The store's boxes and its padding (shipping.md §4.1, the Boxes screen).
 */
final readonly class ListBoxes
{
    public function __construct(
        public string $storeId,
    ) {}
}
