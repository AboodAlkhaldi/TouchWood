<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewMyCompany;

/**
 * One of the account's companies in another store (b2b.md amendment 19(c)): which store, under what
 * name, and where it stands there. Nothing else of it shows on this store's page.
 */
final readonly class ElsewhereView
{
    public function __construct(
        public string $storeId,
        public string $storeNameAr,
        public string $storeNameEn,
        public string $name,
        public string $status,
    ) {}
}
