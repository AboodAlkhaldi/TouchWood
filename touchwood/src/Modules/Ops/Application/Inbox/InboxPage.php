<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Inbox;

/**
 * A page of a bell, newest first (ops.md §1.2): `next` is what to ask for the following page, null on
 * the last; `unread` the bell's count over all of the reader's entries, not this page's.
 */
final readonly class InboxPage
{
    /**
     * @param  list<InboxRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
        public int $unread,
    ) {}
}
