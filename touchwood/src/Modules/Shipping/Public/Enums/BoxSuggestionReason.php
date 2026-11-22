<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Enums;

/**
 * Why an order must be packed by hand (shipping.md §1.7).
 */
enum BoxSuggestionReason: string
{
    /** A size of the order is marked "packed by hand" in this store. */
    case ManualSize = 'MANUAL_SIZE';
    /** A size of the order has no weight, or not all three of its sizes, in Catalog. */
    case NoSizeFacts = 'NO_SIZE_FACTS';
    /** No switched-on box takes the order's pieces. */
    case NothingFits = 'NOTHING_FITS';
}
