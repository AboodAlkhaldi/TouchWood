<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A store was turned on (platform.md §6.1; owner, 2026-10-01). Nothing was deleted while it was off:
 * every read filters on the switch, so whatever it held is there again.
 */
final readonly class StoreActivated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $storeId,
        public CarbonImmutable $occurredAt,
    ) {}
}
