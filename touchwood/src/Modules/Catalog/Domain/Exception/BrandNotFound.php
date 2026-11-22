<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * No such brand (catalog.md §7).
 */
final class BrandNotFound extends CatalogError
{
    public function __construct(public readonly string $id = '')
    {
        parent::__construct('No such brand.');
    }

    public function type(): string
    {
        return 'catalog.brand_not_found';
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
