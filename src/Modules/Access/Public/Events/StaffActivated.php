<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A staff member became active: they accepted their invitation, or were enabled again (spec §6).
 * Ids only, dispatched after commit.
 */
final readonly class StaffActivated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $staffId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
