<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Choosing a deactivated attribute, value, label or warranty (catalog.md §7).
 */
final class ListItemInactive extends CatalogError
{
    public function __construct()
    {
        parent::__construct('That item is deactivated.');
    }

    public function type(): string
    {
        return 'catalog.list_item_inactive';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
