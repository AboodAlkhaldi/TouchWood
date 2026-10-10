<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListVariations;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ListReaders;
use Shared\Application\Unauthorized;

/**
 * **The variations screen's read** (catalog.md §4.4 S4): the attribute sets, under the attributes'
 * own job (`catalog.attribute.manage`, §3), read as every list is (P2).
 */
final readonly class ListVariationsHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private ListReaders $readers,
        private CatalogListReads $reads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListVariations $query): VariationList
    {
        $mayChange = $this->readers->authorize(self::PERMISSION);

        return new VariationList($this->reads->variations(), $this->reads->attributes(), $mayChange);
    }
}
