<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Enums;

/**
 * How a variant sells in a store (catalog.md §1.3, §2.5): retail, wholesale, or both — chosen per
 * variant per store, each mode with its product's own minimum and maximum there.
 */
enum SaleMode: string
{
    case Retail = 'RETAIL';
    case Wholesale = 'WHOLESALE';
}
