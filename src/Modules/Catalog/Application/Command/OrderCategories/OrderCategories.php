<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\OrderCategories;

/**
 * One parent's children in one store's menu, dragged or moved to the top or the bottom (catalog.md
 * §1.5, amendment 16(d)): the ids in their new order. Those not sent keep their order, after them
 * (P29). A null parent is the menu's top level.
 */
final readonly class OrderCategories
{
    /**
     * @param  list<string>  $categoryIds
     */
    public function __construct(
        public string $storeId,
        public ?string $parentId,
        public array $categoryIds,
    ) {}
}
