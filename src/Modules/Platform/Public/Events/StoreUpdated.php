<?php

namespace Modules\Platform\Public\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class StoreUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $changed  names of the attributes that changed, never their values
     */
    public function __construct(
        public string $eventId,
        public string $storeId,
        public array $changed,
        public CarbonImmutable $occurredAt,
    ) {}
}
