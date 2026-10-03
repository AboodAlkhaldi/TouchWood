<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Changing an archived product other than restoring it (catalog.md §7).
 */
final class ProductArchived extends CatalogError
{
    public function __construct()
    {
        parent::__construct('The product is archived; restore it first.');
    }

    public function type(): string
    {
        return 'catalog.product_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
