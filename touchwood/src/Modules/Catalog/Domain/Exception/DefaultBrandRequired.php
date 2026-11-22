<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deactivating or deleting the default brand: another brand is made the default first (catalog.md §1.6, §9.3 #12).
 */
final class DefaultBrandRequired extends CatalogError
{
    public function __construct()
    {
        parent::__construct('Make another brand the default first.');
    }

    public function type(): string
    {
        return 'catalog.default_brand_required';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
