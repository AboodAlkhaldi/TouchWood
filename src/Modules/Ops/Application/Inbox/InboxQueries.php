<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Inbox;

use Modules\Ops\Domain\ValueObject\InboxOwner;

/** The bells' reads (ops.md §1.2): the owner's own entries in their stores, nobody else's. */
interface InboxQueries
{
    /**
     * Newest first, from after the entry named (null: from the newest).
     *
     * @param  'ar'|'en'  $locale
     * @return list<InboxRow> an empty list when the entry named is not theirs
     */
    public function page(InboxOwner $owner, string $locale, ?string $afterId, int $limit): array;

    public function unread(InboxOwner $owner): int;
}
