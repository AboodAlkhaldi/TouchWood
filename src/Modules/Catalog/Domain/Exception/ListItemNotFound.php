<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * An attribute, value, attribute set, label, warranty or word pair that does not exist (catalog.md §7).
 */
final class ListItemNotFound extends CatalogError
{
    public function __construct(public readonly string $id = '')
    {
        parent::__construct('No such item.');
    }

    public function type(): string
    {
        return 'catalog.list_item_not_found';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['id' => $this->id];
    }
}
