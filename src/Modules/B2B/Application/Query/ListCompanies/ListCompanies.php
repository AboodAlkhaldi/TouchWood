<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ListCompanies;

/**
 * The staff list of companies (b2b.md §3.2, amendment 10(g)): filtered by status and by store,
 * searched by company name, CR number, tax number or the reference of an application it sent.
 */
final readonly class ListCompanies
{
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $storeId = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
