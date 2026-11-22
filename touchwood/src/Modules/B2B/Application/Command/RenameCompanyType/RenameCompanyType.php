<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\RenameCompanyType;

/**
 * Staff renaming a company type (b2b.md §3.2).
 */
final readonly class RenameCompanyType
{
    public function __construct(
        public string $typeId,
        public string $nameAr,
        public string $nameEn,
    ) {}
}
