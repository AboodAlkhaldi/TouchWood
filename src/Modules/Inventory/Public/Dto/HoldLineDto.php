<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Dto;

/**
 * One line to hold or ship (inventory.md §2.2): a variant and how many pieces. A gift counts on stock
 * in every store, wired or not (handoff §11.6).
 */
final readonly class HoldLineDto
{
    public function __construct(
        public string $variantId,
        public int $quantity,
        public bool $gift = false,
    ) {}
}
