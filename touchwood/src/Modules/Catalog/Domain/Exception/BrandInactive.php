<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Choosing a deactivated brand (catalog.md §7).
 */
final class BrandInactive extends CatalogError
{
    public function __construct()
    {
        parent::__construct('That brand is deactivated.');
    }

    public function type(): string
    {
        return 'catalog.brand_inactive';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
