<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A category in a store's menu (catalog.md §1.5), its sub-categories in the menu's order.
 */
final readonly class MenuCategory
{
    /**
     * @param  list<MenuCategory>  $children
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $imageMediaId,
        public array $children,
    ) {}
}
