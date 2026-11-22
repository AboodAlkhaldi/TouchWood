<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Putting a product in a category that has sub-categories (catalog.md §1.5, §7).
 */
final class CategoryNotLowest extends CatalogError
{
    public function __construct()
    {
        parent::__construct('Products go only into a category with no sub-categories.');
    }

    public function type(): string
    {
        return 'catalog.category_not_lowest';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
