<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListWarranties;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ListReaders;
use Shared\Application\Unauthorized;

/**
 * **The warranties screen's read** (catalog.md §4.4 S6), read as every list is (P2).
 */
final readonly class ListWarrantiesHandler
{
    public const string PERMISSION = CatalogPermissions::WARRANTY_MANAGE;

    public function __construct(
        private ListReaders $readers,
        private CatalogListReads $reads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListWarranties $query): WarrantyList
    {
        $mayChange = $this->readers->authorize(self::PERMISSION);

        return new WarrantyList($this->reads->warranties(), $mayChange);
    }
}
