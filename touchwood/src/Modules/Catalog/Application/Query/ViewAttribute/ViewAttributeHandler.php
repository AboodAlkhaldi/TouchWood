<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ViewAttribute;

use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Query\Lists\CatalogListReads;
use Modules\Catalog\Application\Query\Lists\ListReaders;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Shared\Application\Unauthorized;

/**
 * **One attribute's page** (catalog.md §4.4 S3): read as the list is (P2); an id that names no
 * attribute is not found.
 */
final readonly class ViewAttributeHandler
{
    public const string PERMISSION = CatalogPermissions::ATTRIBUTE_MANAGE;

    public function __construct(
        private ListReaders $readers,
        private CatalogListReads $reads,
    ) {}

    /**
     * @throws ListItemNotFound|Unauthorized
     */
    public function handle(ViewAttribute $query): AttributeView
    {
        $mayChange = $this->readers->authorize(self::PERMISSION);
        $attribute = $this->reads->attribute($query->attributeId) ?? throw new ListItemNotFound($query->attributeId);

        return new AttributeView($attribute, $this->reads->values($attribute->id), $mayChange);
    }
}
