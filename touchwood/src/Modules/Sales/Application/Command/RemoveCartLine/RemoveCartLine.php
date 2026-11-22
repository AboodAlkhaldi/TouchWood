<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RemoveCartLine;

/** A line taken off the cart (sales.md §1.1). */
final readonly class RemoveCartLine
{
    public function __construct(
        public string $storeId,
        public string $variantId,
        public string $saleMode,
    ) {}
}
