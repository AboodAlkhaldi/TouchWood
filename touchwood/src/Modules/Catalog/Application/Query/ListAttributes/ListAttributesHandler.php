<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListAttributes;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ListReaders;
use Shared\Application\Unauthorized;

/**
 * **The attributes screen's read** (catalog.md §4.4 S3): `catalog.attribute.manage` held in some
 * store reads it (P2); changing needs the job with All stores.
 */
final readonly class ListAttributesHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private ListReaders $readers,
        private CatalogListReads $reads,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(ListAttributes $query): AttributeList
    {
        $mayChange = $this->readers->authorize(self::PERMISSION);

        return new AttributeList($this->reads->attributes(), $mayChange);
    }
}
