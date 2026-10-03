<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value the domain refuses (catalog.md §7): a name too long or on two lines, a slug with a
 * character it does not take, a label of three words, a swatch that is not a colour.
 */
final class InvalidCatalogAttribute extends CatalogError
{
    public function __construct(public readonly string $attribute, public readonly string $reason)
    {
        parent::__construct("Invalid {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'catalog.invalid_catalog_attribute';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute];
    }
}
