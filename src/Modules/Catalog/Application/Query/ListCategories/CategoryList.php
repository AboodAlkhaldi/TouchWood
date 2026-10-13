<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\ListCategories;

use Modules\Catalog\Application\Query\Lists\CategoryRow;

/**
 * The categories, in the English name's order (the screen builds the tree), and what the reader may do: change the tree (the job with
 * All stores), order the chosen store's menu (`catalog.category.rank` in that store), and choose who
 * sees its categories there (`catalog.category.audience` in that store, amendment 14).
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
        public bool $mayChooseAudience = false,
    ) {}
}
