<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer's marketing consent was given or withdrawn (amendment 79): Ops keeps the newsletter
 * list current. Read the customer for the new value.
 *
 * Ids only, after commit.
 */
final readonly class MarketingConsentChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
