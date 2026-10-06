<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Changing a product's attribute set once it has variants (catalog.md §1.7, §7).
 */
final class AttributeSetLocked extends CatalogError
{
    public function __construct()
    {
        parent::__construct('The product has variants, so its attribute set stays.');
    }

    public function type(): string
    {
        return 'catalog.attribute_set_locked';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
