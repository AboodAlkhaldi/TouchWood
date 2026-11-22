<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\AdjustStock;

/**
 * "Add" or "Remove" an amount of one size by hand, with a reason (inventory.md §1.5, §3): Received,
 * Damaged / lost, Offline sale, or Correction with a short note.
 */
final readonly class AdjustStock
{
    /**
     * @param  string  $direction  `HandAdjustment::ADD` or `::REMOVE`
     * @param  string  $reason  a `MovementReason` value: RECEIVED, DAMAGED_LOST, OFFLINE_SALE or CORRECTION
     */
    public function __construct(
        public string $storeId,
        public string $variantId,
        public string $direction,
        public int $quantity,
        public string $reason,
        public ?string $note = null,
    ) {}
}
