<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a category that still holds products or sub-categories (catalog.md §1.5, §7).
 */
final class CategoryNotEmpty extends CatalogError
{
    public function __construct()
    {
        parent::__construct('Move what is in this category first.');
    }

    public function type(): string
    {
        return 'catalog.category_not_empty';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
