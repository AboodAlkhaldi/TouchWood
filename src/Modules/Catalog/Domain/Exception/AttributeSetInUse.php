<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Changing the attributes of a set that products with variants use (catalog.md amendment 3(k)):
 * every variant takes one value of every attribute of its set, so the set's attributes stay while
 * any variant is built on them. Its name may still change.
 */
final class AttributeSetInUse extends CatalogError
{
    public function __construct()
    {
        parent::__construct('Products with variants use this set; its attributes stay.');
    }

    public function type(): string
    {
        return 'catalog.attribute_set_in_use';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
