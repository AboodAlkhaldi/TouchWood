<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Deleting what something still uses — an attribute in a set, a value on a variant, a label on a product (catalog.md §7). Deactivating it stays possible.
 */
final class ListItemInUse extends CatalogError
{
    public function __construct()
    {
        parent::__construct('It is still in use; deactivate it instead.');
    }

    public function type(): string
    {
        return 'catalog.list_item_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
