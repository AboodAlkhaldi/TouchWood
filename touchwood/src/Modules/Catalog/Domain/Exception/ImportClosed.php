<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A decision on an import whose products are being brought in, or are in (catalog.md §1.12,
 * amendment 6): its names and codes are decided before, or again after bringing them in failed.
 */
final class ImportClosed extends CatalogError
{
    public function __construct()
    {
        parent::__construct('The import\'s products are brought in already, or being brought in.');
    }

    public function type(): string
    {
        return 'catalog.import_closed';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
