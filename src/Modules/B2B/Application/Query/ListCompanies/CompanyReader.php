<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ListCompanies;

/**
 * Reads the staff company list straight from the tables: a page, filtered in the query — never after
 * paging — so the page and its total agree (lesson 69).
 */
interface CompanyReader
{
    /**
     * Waiting companies first, the oldest sent first; then the others by their latest status change.
     *
     * @param  list<string>|null  $homeStoreIds  null for every store
     * @return array{0: list<CompanySummary>, 1: int} the page, and the total
     */
    public function companies(?array $homeStoreIds, ?string $search, ?string $status, int $page, int $perPage): array;

    /**
     * How many companies stand in each status, over the same table and stores the list reads, so
     * the admin home's card and the list never disagree (amendment 27).
     *
     * @param  list<string>|null  $homeStoreIds  null for every store
     * @return array<string, int> status => how many; a status nobody is in is left out
     */
    public function statusCounts(?array $homeStoreIds): array;
}
