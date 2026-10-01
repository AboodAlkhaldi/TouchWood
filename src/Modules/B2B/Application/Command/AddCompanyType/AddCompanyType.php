<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\AddCompanyType;

/**
 * Staff adding a company type to a store's list (b2b.md §1.3, §3.2).
 */
final readonly class AddCompanyType
{
    public function __construct(
        public string $storeId,
        public string $nameAr,
        public string $nameEn,
        public int $position,
    ) {}
}
