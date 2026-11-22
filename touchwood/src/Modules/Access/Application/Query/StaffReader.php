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
     * Admins and staff — **never a Super Admin**, whoever reads (amendment 54): Super Admins are a
     * section of their own (superAdmins()), never among the admins.
     *
     * @param  list<string>|null  $readerStoreIds  the reader's own stores; null: every store, and
     *                                             then everyone is visible
     * @param  bool  $fullView  only a Super Admin reads with this true: every admin in full, newest
     *                          first (amendments 43, 46(d))
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function staff(?array $readerStoreIds, bool $fullView, ?string $search, ?string $status, int $page, int $perPage): array;

    /**
     * Every Super Admin, newest first, matching the same search and status as the list — for the
     * Super Admins section a Super Admin alone is given (amendment 54). Few, so not paged.
     *
     * @return list<array<string, mixed>>
     */
    public function superAdmins(?string $search, ?string $status): array;

    /**
     * One staff member, whoever they are: the handler decides whether this reader may see them.
     *
     * @return array<string, mixed>|null
     */
    public function member(string $staffId): ?array;

    /**
     * What the admin panel shows of the person it is shown to, in one statement and without a row
     * lock - a read, on every page (amendment 65): their name, picture, language, whether they are a
     * Super Admin, and their role's name in both languages (none for a Super Admin, who holds no
     * role). Null when no staff member has this id.
     *
     * @return array{first_name: string, last_name: string, avatar_media_id: string|null, locale: string, is_super_admin: bool, role_name_ar: string|null, role_name_en: string|null}|null
     */
    public function shell(string $staffId): ?array;

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
