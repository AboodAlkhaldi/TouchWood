<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The customer used their verification link (spec §6). Ids only, dispatched after commit.
 */
final readonly class CustomerEmailVerified implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $customerId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
