<?php

declare(strict_types=1);

namespace Modules\Access\Application\Query;

/**
 * The rows the staff screens read about staff (spec §3.2, §3.3). Reads only.
 *
 * Who may be seen is decided **in the query**, not after it: a page that dropped rows afterwards
 * would return short pages and a total that counted people the reader may not see (review of step
 * 6).
 */
interface StaffReader
{
    /**
     * @param  list<string>|null  $readerStoreIds  the reader's own stores; null: every store, and
     *                                             then everyone is visible
     * @param  bool  $withSuperAdmins  only a Super Admin reads with this true (amendment 43)
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function staff(?array $readerStoreIds, bool $withSuperAdmins, ?string $search, ?string $status, int $page, int $perPage): array;

    /**
     * One staff member, whoever they are: the handler decides whether this reader may see them.
     *
     * @return array<string, mixed>|null
     */
    public function member(string $staffId): ?array;

    /**
     * Where one person's role reaches beyond - or short of - the stores of their assignment.
     *
     * An exception is one action given its own stores (access.md §1.5). The screen that shows a
     * person's role has to show them, or an admin cannot tell why somebody can do one thing in a
     * store the rest of their role never touches.
     *
     * @return array<string, list<string>> permission => the stores it reaches
     */
    public function exceptionsFor(string $staffId): array;
}
