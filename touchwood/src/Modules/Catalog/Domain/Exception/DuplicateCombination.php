<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A variant with the same values as another of the product, archived ones included (catalog.md §1.2, §7).
 */
final class DuplicateCombination extends CatalogError
{
    public function __construct()
    {
        parent::__construct('Another variant of this product has these values.');
    }

    public function type(): string
    {
        return 'catalog.duplicate_combination';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
