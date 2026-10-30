<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\SetSizePacking;

/**
 * A product size packed by hand in this store, or by the suggestion again (shipping.md §1.7: auto by
 * default, per store). An order with a size packed by hand is "pack by hand" as a whole.
 */
final readonly class SetSizePacking
{
    public function __construct(
        public string $storeId,
        public string $variantId,
        public bool $byHand,
    ) {}
}
