<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListCategories;

use Modules\Catalog\Application\Query\Lists\CategoryRow;

/**
 * The categories, in the English name's order (the screen builds the tree), and what the reader may do: change the tree (the job with
 * All stores), and order the chosen store's menu (`catalog.category.rank` in that store).
 */
final readonly class CategoryList
{
    /**
     * @param  list<CategoryRow>  $categories
     */
    public function __construct(
        public array $categories,
        public ?string $storeId,
        public bool $mayManage,
        public bool $mayRank,
    ) {}
}
