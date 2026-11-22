<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

/**
 * **A size a save left short** (inventory.md §4.3.1 "Toasts", Q20): orders hold more of it than there
 * is now - how many orders, and by how many pieces - for the amber toast after the save: "2 orders are
 * short of 60 cm · Oak". Never refused: staff sort it out (§9.2 #19).
 */
final readonly class ShortSize
{
    public function __construct(
        public string $variantId,
        public int $orders,
        public int $shortBy,
    ) {}
}
