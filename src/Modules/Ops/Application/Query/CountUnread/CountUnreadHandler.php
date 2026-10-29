<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\CountUnread;

use Modules\Ops\Application\Inbox\InboxQueries;
use Modules\Ops\Application\Inbox\InboxReaders;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/** The bell's unread count (ops.md §1.2): my own entries, in the stores my bell shows. */
final readonly class CountUnreadHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private InboxReaders $readers,
        private InboxQueries $queries,
    ) {}

    /** @throws Unauthorized */
    public function handle(CountUnread $query): int
    {
        $this->authorizer->authorize($this->readers->permission(), PermissionScope::global());

        return $this->queries->unread($this->readers->owner());
    }
}
