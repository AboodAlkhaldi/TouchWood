<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Choosing a deactivated category — as a parent, a product's category (catalog.md §7).
 */
final class CategoryInactive extends CatalogError
{
    public function __construct()
    {
        parent::__construct('That category is deactivated.');
    }

    public function type(): string
    {
        return 'catalog.category_inactive';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
