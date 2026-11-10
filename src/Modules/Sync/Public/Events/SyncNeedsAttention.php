<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A store's reads need someone (sync.md §6, §9.3 #18): when a read ends, the store's open entries not
 * yet told of - a sale stopped, a discount or a campaign run skipping a size, a read failed - whichever read
 * opened them. Ops tells the store's holders of View the Sync Report who chose Sync problems (access.md
 * amendment 71). Each entry is told once (another sale, discount or run on the same size is told again);
 * `opened` counts them by kind (`SALE_STOPPED`, `DISCOUNT_SKIPPED`, `CAMPAIGN_SKIPPED` - sync.md §9.5
 * amendment 2 - `READ_FAILED`); `readId` is the read that ended. Sent
 * after the change commits; marked told before it is sent, so Ops's listener is queued.
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
