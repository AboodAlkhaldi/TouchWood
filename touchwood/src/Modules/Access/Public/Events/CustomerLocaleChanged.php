<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A customer changed the language we write to them in (amendment 79): Ops moves them to that
 * language's newsletter list. Read the customer for the new value.
 *
 * Ids only, after commit.
 */
final readonly class CustomerLocaleChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
