<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListBrands;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ListReaders;
use Shared\Application\Unauthorized;

/**
 * **The brands screen's read** (catalog.md §4.4 S1): `catalog.brand.manage` held in some store reads
 * the list (P2); changing it needs the job with All stores, which the answer says.
 */
final readonly class ListBrandsHandler
{
    public const string PERMISSION = CatalogPermissions::BRAND_MANAGE;

    public function __construct(
        private ListReaders $readers,
        private CatalogListReads $reads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListBrands $query): BrandList
    {
        $mayChange = $this->readers->authorize(self::PERMISSION);

        return new BrandList($this->reads->brands(), $mayChange);
    }
}
