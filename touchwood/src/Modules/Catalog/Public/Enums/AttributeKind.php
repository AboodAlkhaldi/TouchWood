<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Enums;

/**
 * An attribute's job (handoff §9.1, catalog.md §1.7): shown in a product's details only, offered as
 * a filter, or making a product's variants. It can change only while the attribute has no values
 * (amendment 1(i)).
 */
enum AttributeKind: string
{
    /** Shown in the details table ("Material: steel"); it has no list of values. */
    case Informational = 'INFORMATIONAL';

    /** A list of values shoppers narrow a category by ("Finish: Black, Nickel"). */
    case Filterable = 'FILTERABLE';

    /** Its values make a product's variants ("Length: 300, 400, 500 mm"). */
    case Variant = 'VARIANT';

    public function hasValues(): bool
    {
        return $this !== self::Informational;
    }
}
