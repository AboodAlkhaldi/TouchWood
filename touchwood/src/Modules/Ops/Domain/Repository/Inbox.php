<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Repository;

use DateTimeImmutable;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\ValueObject\InboxOwner;

/** The inboxes behind the bells (ops.md §1.2, §5: `ops.inbox_entries`). */
interface Inbox
{
    public function add(InboxEntry $entry): void;

    /**
     * Marks one of the owner's entries read, in their stores; one read already stays as it was.
     *
     * @return bool false when no such entry is theirs
     */
    public function markRead(InboxOwner $owner, string $entryId, DateTimeImmutable $at): bool;

    /**
     * Marks every unread entry of the owner's, in their stores, read.
     *
     * @return int how many were unread
     */
    public function markAllRead(InboxOwner $owner, DateTimeImmutable $at): int;
}
