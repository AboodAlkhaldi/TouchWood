<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Changing an attribute's job once it has values (catalog.md amendment 1(i)): its values, and what is built on them, would lose their meaning.
 */
final class AttributeKindLocked extends CatalogError
{
    public function __construct()
    {
        parent::__construct('This attribute has values, so its job stays as it is.');
    }

    public function type(): string
    {
        return 'catalog.attribute_kind_locked';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
