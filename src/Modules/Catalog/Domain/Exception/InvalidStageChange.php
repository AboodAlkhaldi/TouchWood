<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A change the product's stage does not allow (catalog.md §4.1, §7) — a draft-only change on a product that is not a draft.
 */
final class InvalidStageChange extends CatalogError
{
    public function __construct()
    {
        parent::__construct('Not possible at this stage of the product.');
    }

    public function type(): string
    {
        return 'catalog.invalid_stage_change';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
