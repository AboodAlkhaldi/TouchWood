<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Adding or moving a sub-category under a category that holds products (catalog.md §1.5, §7).
 */
final class CategoryHoldsProducts extends CatalogError
{
    public function __construct()
    {
        parent::__construct('That category holds products; move them first.');
    }

    public function type(): string
    {
        return 'catalog.category_holds_products';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
