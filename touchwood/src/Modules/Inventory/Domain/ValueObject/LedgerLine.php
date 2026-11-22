<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

use DateTimeImmutable;

/**
 * **One line of a size's ledger as its history shows it** (inventory.md §1.6, §4.3.3 `StockHistory`):
 * when, the reason, before and after, the note - as typed, or Inventory's own as a code (`coded`; a line
 * from before the codes keeps its text in `note`) -, who or what made it - the actor and whoever queued the
 * job the system ran - and, for a shipment or a return, the order it was for.
 */
final readonly class LedgerLine
{
    public function __construct(
        public int $id,
        public DateTimeImmutable $at,
        public MovementReason $reason,
        public int $before,
        public int $after,
        public ?string $note,
        public string $actorType,
        public ?string $actorId,
        public ?string $requestedByType,
        public ?string $requestedById,
        public ?string $orderId,
        public ?LedgerNote $coded = null,
    ) {}
}
