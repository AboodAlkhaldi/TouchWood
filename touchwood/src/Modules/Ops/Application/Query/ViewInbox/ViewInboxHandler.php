<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ViewInbox;

use Modules\Ops\Application\Inbox\InboxPage;
use Modules\Ops\Application\Inbox\InboxQueries;
use Modules\Ops\Application\Inbox\InboxReaders;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * "My notifications" (ops.md §1.2, §3: own only): a customer's entries in the shop's store, a staff
 * member's over the stores their bell shows - newest first, a page at a time, with the unread count.
 */
final readonly class ViewInboxHandler
{
    public function __construct(
        private Authorizer $authorizer,
        private InboxReaders $readers,
        private InboxQueries $queries,
    ) {}

    /** @throws Unauthorized|InvalidOpsInput */
    public function handle(ViewInbox $query): InboxPage
    {
        $this->authorizer->authorize($this->readers->permission(), PermissionScope::global());
        $owner = $this->readers->owner();
        $after = InboxReaders::id($query->after, 'after');

        // One more than a page: whether a next page exists, without a count.
        $rows = $this->queries->page($owner, InboxReaders::locale($query->locale), $after, InboxReaders::PAGE + 1);
        $more = count($rows) > InboxReaders::PAGE;
        $rows = array_slice($rows, 0, InboxReaders::PAGE);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new InboxPage($rows, $more && $last !== null ? $last->id : null, $this->queries->unread($owner));
    }
}
