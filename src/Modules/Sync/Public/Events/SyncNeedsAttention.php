<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A store's reads need someone (sync.md §6, §9.3 #18): a read ended having opened what staff are told
 * of - a sale stopped, a discount skipped, or the read failed. Ops tells the store's holders of View the
 * Sync Report who chose Sync problems (access.md amendment 71). Each entry is told once; `opened` counts
 * them by kind (`SALE_STOPPED`, `DISCOUNT_SKIPPED`, `READ_FAILED`). Sent after the change commits.
 */
final readonly class SyncNeedsAttention implements ShouldDispatchAfterCommit
{
    /**
     * @param  array<string, int>  $opened
     */
    public function __construct(
        public string $eventId,
        public string $storeId,
        public string $readId,
        public array $opened,
        public DateTimeImmutable $occurredAt,
    ) {}
}
