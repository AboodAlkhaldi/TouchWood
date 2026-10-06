<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Lists;

use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Public\Enums\AttributeKind;

/**
 * What adding and editing an attribute share: its job, as the form sends it.
 */
final class AttributeInput
{
    /**
     * @throws InvalidCatalogAttribute
     */
    public static function kind(string $kind): AttributeKind
    {
        return AttributeKind::tryFrom($kind) ?? throw new InvalidCatalogAttribute('kind', 'informational, filterable or variant-making');
    }
}
