<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewCompany;

/**
 * One company, as staff review it (b2b.md §3.2).
 */
final readonly class ViewCompany
{
    public function __construct(
        public string $companyId,
    ) {}
}
