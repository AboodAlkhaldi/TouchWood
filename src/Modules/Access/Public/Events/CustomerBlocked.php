<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Staff blocked this customer (spec §1.1, §6.1): every session of theirs ends at its next request.
 *
 * Ids only, after commit.
 */
final readonly class CustomerBlocked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
