<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Command\MarkAllInboxRead;

use Carbon\CarbonImmutable;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Domain\Repository\Inbox;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Marks every entry of my own bell read (ops.md §1.2): a customer's in the shop's store, a staff
 * member's over the stores their bell shows - never another store's or another person's.
 */
final readonly class MarkAllInboxReadHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private InboxReaders $readers,
        private Inbox $inbox,
    ) {}

    /**
     * @return int how many were unread
     *
     * @throws Unauthorized
     */
    public function handle(MarkAllInboxRead $command): int
    {
        $this->authorizer->authorize($this->readers->permission(), PermissionScope::global());

        return $this->inbox->markAllRead($this->readers->owner(), CarbonImmutable::now()->toDateTimeImmutable());
    }
}
