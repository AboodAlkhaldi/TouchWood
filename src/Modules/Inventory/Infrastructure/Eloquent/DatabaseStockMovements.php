<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\ValueObject\MovementReason;
use Shared\Application\ActorContext;
use Shared\Domain\ValueObject\StoreId;

/**
 * `inventory.stock_movements` (inventory.md §1.6, §5): one row a change of in stock, by whoever acts
 * now - recorded as the audit log records its actor, with whoever queued the job when the system acts
 * in one. Its time is PostgreSQL's clock.
 */
final readonly class DatabaseStockMovements implements StockMovements
{
    public function __construct(
        private ConnectionInterface $db,
        private ActorContext $actors,
    ) {}

    public function record(StoreId $store, string $variantId, MovementReason $reason, int $before, int $after, ?string $note, string $reference): void
    {
        $actor = $this->actors->current();

        $this->db->table('inventory.stock_movements')->insert([
            'store_id' => $store->value,
            'variant_id' => $variantId,
            'reason' => $reason->value,
            'change' => $after - $before,
            'before' => $before,
            'after' => $after,
            'note' => $note,
            'reference' => $reference,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'requested_by_type' => $actor->requestedBy?->type->value,
            'requested_by_id' => $actor->requestedBy?->id,
        ]);
    }
}
