<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Making an archived product ready, deleting it, or deleting its variant: it is restored first
 * (catalog.md §4.1, §7, amendment 3(m)). Its other changes are allowed, so it can be made whole.
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
