<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\MarkInboxEntryRead;

use Carbon\CarbonImmutable;
use Modules\Ops\Application\Inbox\InboxQueries;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Domain\Exception\InboxEntryNotFound;
use Modules\Ops\Domain\Repository\Inbox;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * Marks one entry of my own bell read (ops.md §1.2, §3: own only); one read already stays as it was.
 * Another person's entry, or one of a store my bell does not show, is not found. My own inbox is not
 * audited: nothing of anyone else's changes.
 */
final readonly class MarkInboxEntryReadHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private InboxReaders $readers,
        private Inbox $inbox,
        private InboxQueries $queries,
    ) {}

    /**
     * @return int the bell's unread count after it
     *
     * @throws Unauthorized|InboxEntryNotFound
     */
    public function handle(MarkInboxEntryRead $command): int
    {
        $this->authorizer->authorize($this->readers->permission(), PermissionScope::global());
        $owner = $this->readers->owner();
        // An id that is not one matches no row: not found, without asking the database.
        $id = strtolower(trim($command->entryId));

        if (! Ulids::valid($id) || ! $this->inbox->markRead($owner, $id, CarbonImmutable::now()->toDateTimeImmutable())) {
            throw new InboxEntryNotFound;
        }

        return $this->queries->unread($owner);
    }
}
