<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Enums;

/**
 * What another module may hold on to of Catalog's (amendment 19): a category, a brand, a product, a
 * variant — so Catalog asks before deleting one.
 */
enum CatalogItem: string
{
    case Category = 'category';
    case Brand = 'brand';
    case Product = 'product';
    case Variant = 'variant';
}
