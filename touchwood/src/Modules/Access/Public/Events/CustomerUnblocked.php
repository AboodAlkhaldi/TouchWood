<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Staff let this customer back in (spec §1.1, §6.1).
 *
 * Ids only, after commit.
 */
final readonly class CustomerUnblocked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
