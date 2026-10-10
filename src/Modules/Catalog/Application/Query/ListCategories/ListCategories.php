<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListCategories;

/**
 * The category tree, and one store's menu order beside it (catalog.md §4.4 S2): the store chosen in
 * the screen's own filter, or none — then the base store's order is shown.
 */
final readonly class ListCategories
{
    public function __construct(
        public ?string $storeId = null,
    ) {}
}
