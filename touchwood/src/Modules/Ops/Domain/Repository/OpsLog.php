<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use DateTimeImmutable;

/** What Ops keeps for twelve months (ops.md §5): inbox entries, sent messages, handled events. */
interface OpsLog
{
    /**
     * Deletes the rows made before the moment.
     *
     * @return array{inbox_entries: int, sent_messages: int, handled_events: int} how many went from each
     */
    public function prune(DateTimeImmutable $before): array;
}
