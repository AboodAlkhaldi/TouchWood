<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * **A recount found sizes that became low, ran out or came back** in a store (inventory.md §1.7, §6.1,
 * amendment 1) - for Ops' low-stock alert (ops.md §1.6 S3). One per store per recount, sent only when a
 * list is not empty, never on a store's first recount; a size in one list at most, each list in id order:
 * **became low** (off the list, now 1 or more free and at or below its threshold), **ran out** (nothing
 * free now, and not before - from low too), **came back** (low or out before, neither now). Ids only,
 * sent after the recount commits; `occurredAt` is the moment the changes were written, as `lowStockChanges`
 * keeps them.
 */
final readonly class LowStockChanged implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $becameLow
     * @param  list<string>  $ranOut
     * @param  list<string>  $cameBack
     */
    public function __construct(
        public string $eventId,
        public string $storeId,
        public array $becameLow,
        public array $ranOut,
        public array $cameBack,
        public DateTimeImmutable $occurredAt,
    ) {}
}
