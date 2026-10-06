<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * No such product (catalog.md §7).
 */
final class ProductNotFound extends CatalogError
{
    public function __construct(public readonly string $id = '')
    {
        parent::__construct('No such product.');
    }

    public function type(): string
    {
        return 'catalog.product_not_found';
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
