<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * No such variant (catalog.md §7).
 */
final class VariantNotFound extends CatalogError
{
    public function __construct(public readonly string $id = '')
    {
        parent::__construct('No such variant.');
    }

    public function type(): string
    {
        return 'catalog.variant_not_found';
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
