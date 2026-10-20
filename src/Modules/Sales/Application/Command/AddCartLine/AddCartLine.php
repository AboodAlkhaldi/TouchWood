<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\AddCartLine;

/** A size added to the cart of the store being browsed, bought retail or wholesale (sales.md §1.1). */
final readonly class AddCartLine
{
    public function __construct(
        public string $storeId,
        public string $variantId,
        public string $saleMode,
        public int $quantity,
    ) {}
}
