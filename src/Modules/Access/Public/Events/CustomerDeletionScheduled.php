<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The customer asked for their account to be deleted (spec §1.10): it is anonymized on that date unless they sign in first.
 *
 * Ids only, after commit.
 */
final readonly class CustomerDeletionScheduled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
