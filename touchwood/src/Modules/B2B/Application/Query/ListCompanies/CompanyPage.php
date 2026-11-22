<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ListCompanies;

/**
 * One page of the staff company list, and how many companies the whole list holds — counted under
 * the same visibility rule as the page, so the two always agree (lesson 69).
 */
final readonly class CompanyPage
{
    /**
     * @param  list<CompanySummary>  $companies
     */
    public function __construct(
        public array $companies,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
