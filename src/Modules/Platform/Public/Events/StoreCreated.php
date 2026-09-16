<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class StoreCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $storeId,
        public CarbonImmutable $occurredAt,
    ) {}
}
