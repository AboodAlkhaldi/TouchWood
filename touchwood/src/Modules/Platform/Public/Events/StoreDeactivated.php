<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A store was turned off (platform.md §6.1; owner, 2026-10-01). Nothing is deleted or rewritten: the
 * store is hidden by every read that filters on the switch, and work already under way in it — its
 * open orders, jobs dispatched in it — continues.
 */
final readonly class StoreDeactivated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $storeId,
        public CarbonImmutable $occurredAt,
    ) {}
}
