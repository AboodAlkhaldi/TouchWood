<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class CurrencyUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $changed  names of the attributes that changed, never their values
     */
    public function __construct(
        public string $eventId,
        public string $currencyCode,
        public array $changed,
        public CarbonImmutable $occurredAt,
    ) {}
}
