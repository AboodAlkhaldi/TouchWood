<?php

declare(strict_types=1);

namespace Modules\Access\Public\Events;

use DateTimeImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A staff member was disabled, or their invitation cancelled (spec §6). Ids only, dispatched after
 * commit.
 */
final readonly class StaffDisabled implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $eventId,
        public string $staffId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
