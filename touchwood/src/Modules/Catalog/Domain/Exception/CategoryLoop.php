<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Moving a category under itself or anything below it (catalog.md §1.5, §7).
 */
final class CategoryLoop extends CatalogError
{
    public function __construct()
    {
        parent::__construct('A category cannot move under itself or anything below it.');
    }

    public function type(): string
    {
        return 'catalog.category_loop';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }
}
