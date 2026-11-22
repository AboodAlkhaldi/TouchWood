<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Over a limit (catalog.md §7): photos, search words, related products, filter values.
 */
final class TooMany extends CatalogError
{
    public function __construct(public readonly string $attribute, public readonly int $max)
    {
        parent::__construct("At most {$this->max} {$this->attribute}.");
    }

    public function type(): string
    {
        return 'catalog.too_many';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute, 'max' => $this->max];
    }
}
