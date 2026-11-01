<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListStaffMessages;

use Shared\Domain\ValueObject\StoreId;

/** The staff messages list's read (ops.md §5: `ops.staff_messages`). */
interface StaffMessageQueries
{
    /**
     * The store's messages of these audiences, newest first, after the one named.
     *
     * @param  non-empty-list<string>  $audiences
     * @return list<StaffMessageRow> an empty list when the message named is not among them
     */
    public function page(StoreId $store, array $audiences, ?string $afterId, int $limit): array;
}
