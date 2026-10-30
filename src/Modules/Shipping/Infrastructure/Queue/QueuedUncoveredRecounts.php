<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Queue;

use Illuminate\Database\Connection;
use Modules\Shipping\Application\Uncovered\UncoveredRecounts;
use Shared\Domain\ValueObject\StoreId;

/**
 * The recount queued once the change commits, so it counts what the change wrote.
 */
final readonly class QueuedUncoveredRecounts implements UncoveredRecounts
{
    public function __construct(
        private Connection $db,
    ) {}

    public function queue(StoreId $store): void
    {
        $this->db->afterCommit(static fn () => CountUncoveredPlacesJob::dispatch($store->value));
    }
}
