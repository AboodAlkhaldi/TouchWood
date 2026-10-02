<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ShopLine;

/**
 * A company account's standing in the store it is browsing, read on every page of the shop it opens
 * (b2b.md §4.4, amendment 19(c)) — so one query, not the company page's whole read.
 */
interface CompanyStandings
{
    public function of(string $customerId, string $storeId): CompanyStanding;
}
