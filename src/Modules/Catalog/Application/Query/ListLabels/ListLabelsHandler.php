<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListLabels;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ListReaders;
use Shared\Application\Unauthorized;

/**
 * **The labels screen's read** (catalog.md §4.4 S5), read as every list is (P2).
 */
final readonly class ListLabelsHandler
{
    public const string PERMISSION = CatalogPermissions::LABEL_MANAGE;

    public function __construct(
        private ListReaders $readers,
        private CatalogListReads $reads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListLabels $query): LabelList
    {
        $mayChange = $this->readers->authorize(self::PERMISSION);

        return new LabelList($this->reads->labels(), $mayChange);
    }
}
