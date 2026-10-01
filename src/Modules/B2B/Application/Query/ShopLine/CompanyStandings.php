<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ShopLine;

/**
 * A company account's standing, read on every page of the shop it opens (b2b.md §4.4) — so one
 * query, not the company page's whole read.
 */
interface CompanyStandings
{
    public function of(string $customerId): CompanyStanding;
}
