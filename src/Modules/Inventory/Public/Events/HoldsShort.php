<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A hand change - a removal or a stocktake - left a size's in stock below what placed orders hold
 * (inventory.md §1.5, §6.1). **Every** order still holding the size is named; none is blocked - Sales
 * marks them "stock was reduced" for staff to sort out (owner, 2026-10-11). Ids only, sent after the
 * change commits.
 */
final readonly class HoldsShort implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $orderIds
     */
    public function __construct(
        public string $eventId,
        public string $storeId,
        public string $variantId,
        public array $orderIds,
        public DateTimeImmutable $occurredAt,
    ) {}
}
