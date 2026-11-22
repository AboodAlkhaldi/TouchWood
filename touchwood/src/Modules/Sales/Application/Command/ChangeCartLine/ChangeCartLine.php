<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ChangeCartLine;

/** A cart line's quantity set (sales.md §1.1). */
final readonly class ChangeCartLine
{
    public function __construct(
        public string $storeId,
        public string $variantId,
        public string $saleMode,
        public int $quantity,
    ) {}
}
