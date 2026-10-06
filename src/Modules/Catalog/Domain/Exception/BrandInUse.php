<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting a brand a product carries, archived ones included (catalog.md §1.6, §7).
 */
final class BrandInUse extends CatalogError
{
    public function __construct()
    {
        parent::__construct('Products carry this brand; move them first.');
    }

    public function type(): string
    {
        return 'catalog.brand_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
